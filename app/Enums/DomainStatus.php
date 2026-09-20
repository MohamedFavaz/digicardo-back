<?php

namespace App\Enums;

enum DomainStatus: string
{
    case Pending = 'pending';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case Active = 'active';
    case Failed = 'failed';
    case Disabled = 'disabled';

    public function isVerified(): bool
    {
        return in_array($this, [self::Verified, self::Active], true);
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
