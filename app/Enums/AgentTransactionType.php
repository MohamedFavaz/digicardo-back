<?php

namespace App\Enums;

/**
 * Types of financial transactions in the agent system.
 */
enum AgentTransactionType: string
{
    case CardSale = 'card_sale';
    case CommissionPayment = 'commission_payment';
    case Adjustment = 'adjustment';
}
