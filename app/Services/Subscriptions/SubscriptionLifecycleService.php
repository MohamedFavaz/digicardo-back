<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SubscriptionLifecycleService
{
    public function __construct(
        protected ?\App\Services\NotificationService $notificationService = null,
        protected ?\App\Services\Email\EmailTemplateService $templateService = null
    ) {
        $this->notificationService = $notificationService ?? app(\App\Services\NotificationService::class);
        $this->templateService = $templateService ?? app(\App\Services\Email\EmailTemplateService::class);
    }

    /**
     * Handle successful payment invoice.
     */
    public function handlePaymentSuccess(string $providerSubscriptionId): ?Subscription
    {
        $subscription = Subscription::where('provider_subscription_id', $providerSubscriptionId)->first();

        if (!$subscription) {
            return null;
        }

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'grace_period_ends_at' => null,
        ]);

        Log::info('[SubscriptionLifecycle] Payment succeeded, renewed active status', [
            'subscription_id' => $subscription->id,
            'provider_sub_id' => $providerSubscriptionId,
        ]);

        // Dispatch payment success notification
        $user = $subscription->user;
        if ($user) {
            try {
                $appUrl = rtrim(config('app.frontend_url', config('app.url', 'https://Digicardo.app')), '/');
                $billingUrl = "{$appUrl}/dashboard/billing";
                $planName = $subscription->plan?->name ?? 'Pro';

                $this->notificationService->createForUser(
                    user: $user,
                    type: \App\Enums\NotificationType::SubscriptionRenewed,
                    category: \App\Enums\NotificationCategory::Subscription,
                    title: "Payment Succeeded — Digicardo {$planName}",
                    body: "Your payment for Digicardo {$planName} was processed successfully. Thank you for your continued support!",
                    data: ['subscription_id' => $subscription->id],
                    sendEmail: true
                );
            } catch (\Throwable $e) {
                Log::warning('[SubscriptionLifecycle] Failed to dispatch payment success notification', ['error' => $e->getMessage()]);
            }
        }

        return $subscription;
    }

    /**
     * Handle payment failure: transition to PastDue and set a configurable Grace Period.
     */
    public function handlePaymentFailed(string $providerSubscriptionId, ?string $reason = null): ?Subscription
    {
        $subscription = Subscription::where('provider_subscription_id', $providerSubscriptionId)->first();

        if (!$subscription) {
            return null;
        }

        $graceDays = (int) config('services.subscription.grace_period_days', 7);
        $gracePeriodEnd = Carbon::now()->addDays($graceDays);

        $subscription->update([
            'status' => SubscriptionStatus::PastDue,
            'grace_period_ends_at' => $gracePeriodEnd,
        ]);

        Log::warning('[SubscriptionLifecycle] Payment failed, grace period started', [
            'subscription_id' => $subscription->id,
            'provider_sub_id' => $providerSubscriptionId,
            'grace_period_ends_at' => $gracePeriodEnd->toIso8601String(),
            'reason' => $reason,
        ]);

        // Dispatch payment failed notification
        $user = $subscription->user;
        if ($user) {
            try {
                $appUrl = rtrim(config('app.frontend_url', config('app.url', 'https://Digicardo.app')), '/');
                $billingUrl = "{$appUrl}/dashboard/billing";
                $planName = $subscription->plan?->name ?? 'Pro';
                $template = $this->templateService->renderPaymentFailed(
                    $user->name ?: $user->email,
                    $planName,
                    $gracePeriodEnd->toFormattedDateString(),
                    $billingUrl
                );

                $this->notificationService->createForUser(
                    user: $user,
                    type: \App\Enums\NotificationType::SubscriptionPaymentFailed,
                    category: \App\Enums\NotificationCategory::Subscription,
                    title: "Payment Failed — Action Required",
                    body: "We were unable to renew your Digicardo {$planName} subscription. Your grace period is active until {$gracePeriodEnd->toFormattedDateString()}.",
                    data: ['subscription_id' => $subscription->id, 'grace_period_ends_at' => $gracePeriodEnd->toIso8601String()],
                    sendEmail: true,
                    customSubject: $template['subject'],
                    emailHtml: $template['html'],
                    emailText: $template['text']
                );
            } catch (\Throwable $e) {
                Log::warning('[SubscriptionLifecycle] Failed to dispatch payment failed notification', ['error' => $e->getMessage()]);
            }
        }

        return $subscription;
    }

    /**
     * Handle cancellation event from provider.
     */
    public function handleSubscriptionCanceled(string $providerSubscriptionId, bool $atPeriodEnd = true): ?Subscription
    {
        $subscription = Subscription::where('provider_subscription_id', $providerSubscriptionId)->first();

        if (!$subscription) {
            return null;
        }

        if ($atPeriodEnd) {
            $subscription->update([
                'cancel_at_period_end' => true,
                'canceled_at' => now(),
            ]);
        } else {
            $subscription->update([
                'status' => SubscriptionStatus::Canceled,
                'cancel_at_period_end' => false,
                'canceled_at' => now(),
            ]);
        }

        Log::info('[SubscriptionLifecycle] Subscription cancellation updated', [
            'subscription_id' => $subscription->id,
            'at_period_end' => $atPeriodEnd,
        ]);

        $user = $subscription->user;
        if ($user) {
            try {
                $appUrl = rtrim(config('app.frontend_url', config('app.url', 'https://Digicardo.app')), '/');
                $billingUrl = "{$appUrl}/dashboard/billing";
                $planName = $subscription->plan?->name ?? 'Pro';
                $periodEnd = $subscription->current_period_end ? $subscription->current_period_end->toFormattedDateString() : 'period end';
                $template = $this->templateService->renderSubscriptionCancelled(
                    $user->name ?: $user->email,
                    $planName,
                    $periodEnd,
                    $billingUrl
                );

                $this->notificationService->createForUser(
                    user: $user,
                    type: \App\Enums\NotificationType::SubscriptionCancelled,
                    category: \App\Enums\NotificationCategory::Subscription,
                    title: "Subscription Cancellation Scheduled",
                    body: "Your Digicardo {$planName} subscription cancellation is scheduled for {$periodEnd}. You can resume anytime.",
                    data: ['subscription_id' => $subscription->id],
                    sendEmail: true,
                    customSubject: $template['subject'],
                    emailHtml: $template['html'],
                    emailText: $template['text']
                );
            } catch (\Throwable $e) {
                Log::warning('[SubscriptionLifecycle] Failed to dispatch cancellation notification', ['error' => $e->getMessage()]);
            }
        }

        return $subscription;
    }

    /**
     * Handle immediate subscription deletion / termination.
     */
    public function handleSubscriptionDeleted(string $providerSubscriptionId): ?Subscription
    {
        $subscription = Subscription::where('provider_subscription_id', $providerSubscriptionId)->first();

        if (!$subscription) {
            return null;
        }

        $subscription->update([
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => now(),
            'grace_period_ends_at' => null,
        ]);

        Log::info('[SubscriptionLifecycle] Subscription deleted from provider', [
            'subscription_id' => $subscription->id,
        ]);

        return $subscription;
    }
}
