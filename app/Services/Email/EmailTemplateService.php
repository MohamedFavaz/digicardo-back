<?php

namespace App\Services\Email;

class EmailTemplateService
{
    /**
     * Wrap content in the branded Digicardo base email layout.
     */
    public function renderLayout(string $title, string $contentHtml, ?string $actionUrl = null, ?string $actionText = null): string
    {
        $buttonHtml = '';
        if ($actionUrl && $actionText) {
            $buttonHtml = <<<HTML
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin: 28px 0;">
                <tr>
                    <td align="center" style="border-radius: 12px; background: #6366f1;">
                        <a href="{$actionUrl}" target="_blank" style="font-size: 14px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 12px; display: inline-block; font-weight: 700; letter-spacing: 0.02em;">
                            {$actionText}
                        </a>
                    </td>
                </tr>
            </table>
HTML;
        }

        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #0f172a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #e2e8f0;">
    <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="background-color: #0f172a; padding: 40px 16px;">
        <tr>
            <td align="center">
                <!-- Brand Header -->
                <table role="presentation" width="100%" style="max-width: 580px; margin-bottom: 24px;">
                    <tr>
                        <td align="center">
                            <span style="font-size: 22px; font-weight: 900; color: #ffffff; letter-spacing: -0.03em;">
                                ⚡ Digicardo
                            </span>
                        </td>
                    </tr>
                </table>

                <!-- Main Card -->
                <table role="presentation" width="100%" style="max-width: 580px; background-color: #1e293b; border-radius: 20px; border: 1px solid #334155; padding: 40px 32px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);">
                    <tr>
                        <td>
                            <h1 style="margin: 0 0 16px 0; font-size: 24px; font-weight: 800; color: #ffffff; letter-spacing: -0.02em;">
                                {$title}
                            </h1>
                            <div style="font-size: 15px; line-height: 1.6; color: #cbd5e1;">
                                {$contentHtml}
                            </div>
                            {$buttonHtml}
                            <hr style="border: none; border-top: 1px solid #334155; margin: 32px 0 20px 0;">
                            <p style="margin: 0; font-size: 12px; line-height: 1.5; color: #94a3b8;">
                                If you did not initiate this request, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- Footer -->
                <table role="presentation" width="100%" style="max-width: 580px; margin-top: 24px;">
                    <tr>
                        <td align="center" style="font-size: 12px; color: #64748b;">
                            &copy; {$year} Digicardo. All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    /**
     * Verification Email.
     */
    public function renderVerifyEmail(string $name, string $verifyUrl): array
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $html = "<p>Hi {$safeName},</p><p>Thank you for signing up for Digicardo! Please verify your email address to confirm your account.</p>";
        $fullHtml = $this->renderLayout('Verify your Digicardo email', $html, $verifyUrl, 'Verify Email Address');
        $text = "Hi {$name},\n\nThank you for signing up for Digicardo! Please verify your email by visiting:\n{$verifyUrl}\n\nDigicardo Team";

        return ['subject' => 'Verify your email address — Digicardo', 'html' => $fullHtml, 'text' => $text];
    }

    /**
     * Password Reset Email.
     */
    public function renderPasswordReset(string $name, string $resetUrl): array
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $html = "<p>Hi {$safeName},</p><p>We received a request to reset your Digicardo account password. Click the button below to choose a new password. This link will expire in 60 minutes.</p>";
        $fullHtml = $this->renderLayout('Reset your Digicardo password', $html, $resetUrl, 'Reset Password');
        $text = "Hi {$name},\n\nWe received a password reset request. Reset your password using the link below (valid for 60 minutes):\n{$resetUrl}\n\nDigicardo Team";

        return ['subject' => 'Reset your password — Digicardo', 'html' => $fullHtml, 'text' => $text];
    }

    /**
     * Welcome Email.
     */
    public function renderWelcome(string $name, string $dashboardUrl): array
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $html = "<p>Welcome to Digicardo, {$safeName}!</p><p>Your creator workspace is ready. You can now build your bio profile, customize your theme, link social channels, and share your links with the world.</p>";
        $fullHtml = $this->renderLayout('Welcome to Digicardo ⚡', $html, $dashboardUrl, 'Go to Dashboard');
        $text = "Welcome to Digicardo, {$name}!\n\nYour creator workspace is ready. Access your dashboard at:\n{$dashboardUrl}\n\nDigicardo Team";

        return ['subject' => 'Welcome to Digicardo! ⚡', 'html' => $fullHtml, 'text' => $text];
    }

    /**
     * Contact Form Submission Received Email.
     */
    public function renderContactReceived(string $ownerName, string $senderName, string $senderEmail, string $message, string $dashboardUrl): array
    {
        $safeOwner = htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8');
        $safeSender = htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($senderEmail, ENT_QUOTES, 'UTF-8');
        $safeMsg = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        $html = <<<HTML
        <p>Hi {$safeOwner},</p>
        <p>You received a new contact submission on your Digicardo profile from <strong>{$safeSender}</strong> ({$safeEmail}):</p>
        <div style="background-color: #0f172a; border-radius: 12px; padding: 16px; margin: 16px 0; border: 1px solid #334155; font-style: italic;">
            {$safeMsg}
        </div>
HTML;
        $fullHtml = $this->renderLayout('New contact form submission', $html, $dashboardUrl, 'View in Dashboard');
        $text = "Hi {$ownerName},\n\nYou received a new contact submission from {$senderName} ({$senderEmail}):\n\n{$message}\n\nView submissions: {$dashboardUrl}";

        return ['subject' => "New message from {$senderName} — Digicardo", 'html' => $fullHtml, 'text' => $text];
    }

    /**
     * Subscription Started Email.
     */
    public function renderSubscriptionStarted(string $name, string $planName, string $billingUrl): array
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safePlan = htmlspecialchars($planName, ENT_QUOTES, 'UTF-8');
        $html = "<p>Hi {$safeName},</p><p>Your subscription to <strong>Digicardo {$safePlan}</strong> is now active! Enjoy unlimited blocks, custom domains, detailed analytics, and premium templates.</p>";
        $fullHtml = $this->renderLayout("You are now on Digicardo {$safePlan} 🚀", $html, $billingUrl, 'Manage Subscription');
        $text = "Hi {$name},\n\nYour subscription to Digicardo {$planName} is now active!\n\nManage billing: {$billingUrl}";

        return ['subject' => "Welcome to Digicardo {$planName}! 🚀", 'html' => $fullHtml, 'text' => $text];
    }

    /**
     * Payment Failed Email.
     */
    public function renderPaymentFailed(string $name, string $planName, string $gracePeriodEnds, string $billingUrl): array
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safePlan = htmlspecialchars($planName, ENT_QUOTES, 'UTF-8');
        $safeDate = htmlspecialchars($gracePeriodEnds, ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
        <p>Hi {$safeName},</p>
        <p>We were unable to process the renewal payment for your <strong>Digicardo {$safePlan}</strong> subscription.</p>
        <p>Your subscription features will remain active during your grace period until <strong>{$safeDate}</strong>. Please update your payment method to prevent interruption.</p>
HTML;
        $fullHtml = $this->renderLayout('Payment Failed — Action Required', $html, $billingUrl, 'Update Payment Method');
        $text = "Hi {$name},\n\nWe could not process your payment for Digicardo {$planName}. Your grace period is active until {$gracePeriodEnds}. Please update billing at: {$billingUrl}";

        return ['subject' => 'Action Required: Payment failed for Digicardo subscription', 'html' => $fullHtml, 'text' => $text];
    }

    /**
     * Subscription Cancelled Email.
     */
    public function renderSubscriptionCancelled(string $name, string $planName, string $periodEnds, string $billingUrl): array
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safePlan = htmlspecialchars($planName, ENT_QUOTES, 'UTF-8');
        $safeDate = htmlspecialchars($periodEnds, ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
        <p>Hi {$safeName},</p>
        <p>Your <strong>Digicardo {$safePlan}</strong> subscription has been scheduled for cancellation. You will continue to have access to all {$safePlan} features until <strong>{$safeDate}</strong>.</p>
        <p>You can resume your subscription at any time with one click before your period ends.</p>
HTML;
        $fullHtml = $this->renderLayout('Subscription Cancellation Scheduled', $html, $billingUrl, 'Resume Subscription');
        $text = "Hi {$name},\n\nYour Digicardo {$planName} subscription cancellation is scheduled for {$periodEnds}. You can resume anytime at: {$billingUrl}";

        return ['subject' => "Digicardo {$planName} Cancellation Scheduled", 'html' => $fullHtml, 'text' => $text];
    }

    /**
     * Subscription Resumed Email.
     */
    public function renderSubscriptionResumed(string $name, string $planName, string $billingUrl): array
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safePlan = htmlspecialchars($planName, ENT_QUOTES, 'UTF-8');
        $html = "<p>Hi {$safeName},</p><p>Your <strong>Digicardo {$safePlan}</strong> subscription has been resumed successfully. Your auto-renewal is active.</p>";
        $fullHtml = $this->renderLayout('Subscription Resumed', $html, $billingUrl, 'Manage Subscription');
        $text = "Hi {$name},\n\nYour Digicardo {$planName} subscription has been resumed successfully!\n\n{$billingUrl}";

        return ['subject' => "Digicardo {$planName} Resumed", 'html' => $fullHtml, 'text' => $text];
    }

    /**
     * Domain Verified Email.
     */
    public function renderDomainVerified(string $name, string $domainName, string $domainsUrl): array
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeDomain = htmlspecialchars($domainName, ENT_QUOTES, 'UTF-8');
        $html = "<p>Hi {$safeName},</p><p>Good news! Your custom domain <strong>{$safeDomain}</strong> has been successfully verified and is now live and SSL secured.</p>";
        $fullHtml = $this->renderLayout('Domain Successfully Verified 🌐', $html, $domainsUrl, 'View Domains');
        $text = "Hi {$name},\n\nYour custom domain {$domainName} is verified and active!\n\nView domains: {$domainsUrl}";

        return ['subject' => "Domain Verified: {$domainName} — Digicardo", 'html' => $fullHtml, 'text' => $text];
    }

    /**
     * Domain Verification Failed Email.
     */
    public function renderDomainFailed(string $name, string $domainName, string $domainsUrl): array
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeDomain = htmlspecialchars($domainName, ENT_QUOTES, 'UTF-8');
        $html = "<p>Hi {$safeName},</p><p>We were unable to verify DNS records for <strong>{$safeDomain}</strong>. Please ensure your CNAME or TXT verification records are properly configured with your registrar.</p>";
        $fullHtml = $this->renderLayout('Domain Verification Issue', $html, $domainsUrl, 'Check DNS Settings');
        $text = "Hi {$name},\n\nDNS verification for {$domainName} was unsuccessful. Please check DNS settings at: {$domainsUrl}";

        return ['subject' => "Action Required: Domain verification for {$domainName}", 'html' => $fullHtml, 'text' => $text];
    }
}
