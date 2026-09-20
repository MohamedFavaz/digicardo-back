<?php

namespace App\Services\Subscriptions;

use App\DTOs\Subscriptions\SubscriptionProviderData;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SubscriptionSyncService
{
    /**
     * Synchronize provider subscription data into local database.
     */
    public function syncFromProviderData(SubscriptionProviderData $data, ?User $user = null): Subscription
    {
        // 1. Locate local plan by code
        $plan = Plan::where('code', $data->planCode)->orWhere('slug', $data->planCode)->first();
        if (!$plan) {
            $plan = Plan::where('code', 'pro')->firstOrFail();
        }

        // 2. Find existing subscription by provider subscription ID or customer ID
        $query = Subscription::query();
        if ($data->providerSubscriptionId) {
            $query->where('provider_subscription_id', $data->providerSubscriptionId);
        } elseif ($data->providerCustomerId) {
            $query->where('provider_customer_id', $data->providerCustomerId);
        } elseif ($user) {
            $query->where('user_id', $user->id);
        }

        $subscription = $query->latest('created_at')->first();

        // 3. Resolve user
        if (!$user && $subscription) {
            $user = $subscription->user;
        }

        if (!$user && isset($data->metadata['user_id'])) {
            $user = User::find($data->metadata['user_id']);
        }

        if (!$user) {
            throw new \RuntimeException("Unable to associate subscription [{$data->providerSubscriptionId}] with a Digicardo user.");
        }

        // 4. Out-of-order event protection:
        // If existing subscription has a newer period start or updated_at, don't overwrite with older data
        if ($subscription && $subscription->current_period_start && $data->currentPeriodStart) {
            if ($data->currentPeriodStart->lt($subscription->current_period_start)) {
                Log::warning('[SubscriptionSync] Ignored out-of-order older subscription update', [
                    'subscription_id' => $subscription->id,
                    'existing_start' => $subscription->current_period_start->toIso8601String(),
                    'incoming_start' => $data->currentPeriodStart->toIso8601String(),
                ]);
                return $subscription;
            }
        }

        $attributes = [
            'plan_id' => $plan->id,
            'provider' => $data->provider,
            'provider_customer_id' => $data->providerCustomerId ?: ($subscription?->provider_customer_id),
            'provider_subscription_id' => $data->providerSubscriptionId ?: ($subscription?->provider_subscription_id),
            'provider_price_id' => $data->providerPriceId ?: ($subscription?->provider_price_id),
            'status' => $data->status,
            'billing_interval' => $data->billingInterval,
            'current_period_start' => $data->currentPeriodStart ?: ($subscription?->current_period_start ?? now()),
            'current_period_end' => $data->currentPeriodEnd ?: ($subscription?->current_period_end ?? now()->addMonth()),
            'cancel_at_period_end' => $data->cancelAtPeriodEnd,
            'canceled_at' => $data->canceledAt ?: ($data->cancelAtPeriodEnd ? ($subscription?->canceled_at ?? now()) : null),
            'trial_ends_at' => $data->trialEndsAt ?: ($subscription?->trial_ends_at),
            'grace_period_ends_at' => $data->gracePeriodEndsAt ?: ($subscription?->grace_period_ends_at),
            'metadata' => $data->metadata ?: ($subscription?->metadata ?? []),
        ];

        if ($subscription) {
            $subscription->update($attributes);
        } else {
            $attributes['user_id'] = $user->id;
            $subscription = Subscription::create($attributes);
        }

        return $subscription->fresh();
    }
}
