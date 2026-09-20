<?php

namespace App\Services;

use App\Models\ContactSubmission;
use App\Models\Profile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ContactSubmissionService
{
    public function __construct(
        protected ?NotificationService $notificationService = null,
        protected ?\App\Services\Email\EmailTemplateService $templateService = null
    ) {
        $this->notificationService = $notificationService ?? app(NotificationService::class);
        $this->templateService = $templateService ?? app(\App\Services\Email\EmailTemplateService::class);
    }

    /**
     * Store a public contact form submission safely.
     *
     * @param Profile $profile
     * @param array{name: string, email: string, phone?: ?string, message: string, website_hp?: ?string} $data
     * @param string $ip
     * @return ContactSubmission
     */
    public function submit(Profile $profile, array $data, string $ip): ContactSubmission
    {
        // 1. Honeypot check: Bots filling hidden input are silently dropped or rejected
        if (!empty($data['website_hp'])) {
            throw new UnprocessableEntityHttpException('Spam submission detected.');
        }

        // 2. Rate limiting: 5 submissions per 15 minutes per IP per profile
        $rateLimitKey = 'contact_submit:' . $profile->id . ':' . md5($ip);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            throw new TooManyRequestsHttpException($seconds, 'Too many contact requests. Please try again later.');
        }

        RateLimiter::hit($rateLimitKey, 900); // 15 min window

        // 3. Keyed daily pseudonymized IP hash (prevents long-term IP tracking, avoids unsalted sha256)
        $ipHash = hash_hmac('sha256', $ip, config('app.key', 'Digicardo-salt') . date('Y-m-d'));

        $submission = ContactSubmission::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $profile->id,
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'phone' => !empty($data['phone']) ? trim($data['phone']) : null,
            'message' => trim($data['message']),
            'ip_hash' => $ipHash,
        ]);

        // 4. Asynchronously notify profile owner if owner user exists
        $user = $profile->user;
        if ($user) {
            try {
                $appUrl = rtrim(config('app.frontend_url', config('app.url', 'https://Digicardo.app')), '/');
                $dashboardUrl = "{$appUrl}/dashboard";
                $template = $this->templateService->renderContactReceived(
                    $user->name ?: $user->email,
                    $submission->name,
                    $submission->email,
                    $submission->message,
                    $dashboardUrl
                );

                $this->notificationService->createForUser(
                    user: $user,
                    type: \App\Enums\NotificationType::ContactReceived,
                    category: \App\Enums\NotificationCategory::Contact,
                    title: "New contact message from {$submission->name}",
                    body: "You received a new message from {$submission->name} ({$submission->email}): {$submission->message}",
                    data: [
                        'submission_id' => $submission->id,
                        'name' => $submission->name,
                        'email' => $submission->email,
                    ],
                    sendEmail: true,
                    customSubject: $template['subject'],
                    emailHtml: $template['html'],
                    emailText: $template['text']
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[ContactSubmissionService] Failed to queue contact notification', [
                    'profile_id' => $profile->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $submission;
    }
}
