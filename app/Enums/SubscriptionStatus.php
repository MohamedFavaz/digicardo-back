<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case Unpaid = 'unpaid';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case Paused = 'paused';
    case GracePeriod = 'grace_period';

    /**
     * Determine if subscription status directly grants active entitlements.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Active, self::Trialing, self::GracePeriod]);
    }
}
