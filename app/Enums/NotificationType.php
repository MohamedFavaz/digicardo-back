<?php

namespace App\Enums;

enum NotificationType: string
{
    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';
    case Welcome = 'welcome';
    case ContactReceived = 'contact_received';
    case SubscriptionStarted = 'subscription_started';
    case SubscriptionRenewed = 'subscription_renewed';
    case SubscriptionPaymentFailed = 'subscription_payment_failed';
    case SubscriptionCancelled = 'subscription_cancelled';
    case SubscriptionResumed = 'subscription_resumed';
    case DomainVerified = 'domain_verified';
    case DomainFailed = 'domain_failed';
    case SystemAlert = 'system_alert';
}
