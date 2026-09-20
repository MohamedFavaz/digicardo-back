<?php

namespace App\Enums;

enum ProfileModerationStatus: string
{
    case Active = 'active';
    case UnderReview = 'under_review';
    case Restricted = 'restricted';
    case Suspended = 'suspended';

    public function isPubliclyRenderable(): bool
    {
        return $this === self::Active || $this === self::UnderReview;
    }

    public function isRestrictedOrSuspended(): bool
    {
        return $this === self::Restricted || $this === self::Suspended;
    }
}
