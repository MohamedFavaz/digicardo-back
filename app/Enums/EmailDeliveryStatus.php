<?php

namespace App\Enums;

enum EmailDeliveryStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case PermanentlyFailed = 'permanently_failed';
}
