<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Enums\NotificationType;
use App\Models\User;
use App\Services\Email\EmailTemplateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class AuthEmailService
{
    public function __construct(
        protected NotificationService $notificationService,
        protected EmailTemplateService $templateService
    ) {
    }

    /**
     * Send email verification notification to user.
     */
    public function sendVerificationNotification(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $appUrl = rtrim(config('app.frontend_url', config('app.url', 'https://Digicardo.app')), '/');

        // Generate signed URL parameters (valid for 60 minutes)
        $expires = now()->addMinutes(60)->timestamp;
        $hash = sha1($user->email);
        $signature = hash_hmac('sha256', "verify:{$user->id}:{$hash}:{$expires}", config('app.key'));

        $verifyUrl = "{$appUrl}/verify-email?id={$user->id}&hash={$hash}&expires={$expires}&signature={$signature}";

        $template = $this->templateService->renderVerifyEmail($user->name ?: $user->email, $verifyUrl);

        $this->notificationService->createForUser(
            user: $user,
            type: NotificationType::EmailVerification,
            category: NotificationCategory::Security,
            title: 'Verify your email address',
            body: 'Please verify your email address to confirm your Digicardo account.',
            data: ['email' => $user->email],
            sendEmail: true,
            customSubject: $template['subject'],
            emailHtml: $template['html'],
            emailText: $template['text']
        );
    }

    /**
     * Verify email with cryptographic signature and hash verification.
     */
    public function verifyEmail(User $user, string $hash, int $expires, string $signature): bool
    {
        if ($user->hasVerifiedEmail()) {
            return true;
        }

        // 1. Check expiration
        if (now()->timestamp > $expires) {
            return false;
        }

        // 2. Verify signature
        $expectedSignature = hash_hmac('sha256', "verify:{$user->id}:{$hash}:{$expires}", config('app.key'));
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        // 3. Verify hash matches current user email
        if (!hash_equals(sha1($user->email), $hash)) {
            return false;
        }

        $user->markEmailAsVerified();

        Log::info('[AuthEmailService] User email verified successfully', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return true;
    }

    /**
     * Send secure password reset link.
     */
    public function sendPasswordResetLink(string $email): void
    {
        $normalizedEmail = strtolower(trim($email));
        $user = User::where('email', $normalizedEmail)->first();

        // If user does not exist, return silently to prevent email enumeration
        if (!$user) {
            Log::info('[AuthEmailService] Password reset requested for non-existent email', [
                'email' => $normalizedEmail,
            ]);
            return;
        }

        // Generate high-entropy 64-char raw token and store hashed token
        $rawToken = Str::random(64);
        $hashedToken = hash('sha256', $rawToken);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $normalizedEmail],
            [
                'token' => $hashedToken,
                'created_at' => now(),
            ]
        );

        $appUrl = rtrim(config('app.frontend_url', config('app.url', 'https://Digicardo.app')), '/');
        $resetUrl = "{$appUrl}/reset-password?token={$rawToken}&email=" . urlencode($normalizedEmail);

        $template = $this->templateService->renderPasswordReset($user->name ?: $user->email, $resetUrl);

        $this->notificationService->createForUser(
            user: $user,
            type: NotificationType::PasswordReset,
            category: NotificationCategory::Security,
            title: 'Password Reset Request',
            body: 'A request was received to reset your password. The link will expire in 60 minutes.',
            data: ['email' => $normalizedEmail],
            sendEmail: true,
            customSubject: $template['subject'],
            emailHtml: $template['html'],
            emailText: $template['text']
        );

        Log::info('[AuthEmailService] Password reset email dispatched', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Reset password using token.
     */
    public function resetPassword(string $email, string $rawToken, string $newPassword): bool
    {
        $normalizedEmail = strtolower(trim($email));
        $record = DB::table('password_reset_tokens')->where('email', $normalizedEmail)->first();

        if (!$record) {
            return false;
        }

        // Check if token expired (valid for 60 minutes)
        if (Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $normalizedEmail)->delete();
            return false;
        }

        // Verify token hash
        $hashedInput = hash('sha256', $rawToken);
        if (!hash_equals($record->token, $hashedInput)) {
            return false;
        }

        $user = User::where('email', $normalizedEmail)->first();
        if (!$user) {
            return false;
        }

        // Update password
        $user->forceFill([
            'password' => Hash::make($newPassword),
        ])->save();

        // Invalidate single-use token
        DB::table('password_reset_tokens')->where('email', $normalizedEmail)->delete();

        // Invalidate previous API tokens
        $user->tokens()->delete();

        // Invalidate all active sessions for this user on password reset
        try {
            app(AccountSessionService::class)->revokeAllSessions(
                $user,
                \App\Enums\SessionRevocationReason::PasswordReset->value
            );
        } catch (\Throwable $e) {
            Log::warning('[AuthEmailService] Failed revoking sessions during password reset', ['error' => $e->getMessage()]);
        }

        // Record security audit event
        \App\Models\AccountSecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => \App\Enums\AccountSecurityEventType::PasswordReset,
            'metadata' => ['action' => 'password_reset_completed'],
            'ip_hash' => hash_hmac('sha256', (string) request()->ip(), config('app.key', 'Digicardo-salt')),
            'created_at' => now(),
        ]);

        // Send security confirmation notification
        $this->notificationService->createForUser(
            user: $user,
            type: NotificationType::SystemAlert,
            category: NotificationCategory::Security,
            title: 'Password Successfully Reset',
            body: 'Your Digicardo account password was reset using a recovery link. All active device sessions have been revoked.',
            data: ['changed_at' => now()->toIso8601String()],
            sendEmail: true
        );

        Log::info('[AuthEmailService] Password reset succeeded', [
            'user_id' => $user->id,
        ]);

        return true;
    }

    /**
     * Send welcome email after registration.
     */
    public function sendWelcomeNotification(User $user): void
    {
        $appUrl = rtrim(config('app.frontend_url', config('app.url', 'https://Digicardo.app')), '/');
        $dashboardUrl = "{$appUrl}/dashboard";

        $template = $this->templateService->renderWelcome($user->name ?: $user->email, $dashboardUrl);

        $this->notificationService->createForUser(
            user: $user,
            type: NotificationType::Welcome,
            category: NotificationCategory::System,
            title: 'Welcome to Digicardo! ⚡',
            body: 'Your workspace is ready. Customize your profile, add blocks, and share your links.',
            data: ['user_id' => $user->id],
            sendEmail: true,
            customSubject: $template['subject'],
            emailHtml: $template['html'],
            emailText: $template['text']
        );
    }
}
