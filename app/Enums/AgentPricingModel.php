<?php

namespace App\Enums;

/**
 * Pricing model for agent commission calculations.
 */
enum AgentPricingModel: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
}
