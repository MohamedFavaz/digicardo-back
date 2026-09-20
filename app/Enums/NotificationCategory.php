<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case Security = 'security';
    case Contact = 'contact';
    case Subscription = 'subscription';
    case Domain = 'domain';
    case System = 'system';
    case Marketing = 'marketing';
}
