<?php

namespace App\Enums;

/**
 * Status values for agent commission payment records.
 */
enum AgentPaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
}
