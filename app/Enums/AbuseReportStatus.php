<?php

namespace App\Enums;

enum AbuseReportStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function isPending(): bool
    {
        return $this === self::Open || $this === self::Investigating;
    }

    public function isCompleted(): bool
    {
        return $this === self::Resolved || $this === self::Dismissed;
    }
}
