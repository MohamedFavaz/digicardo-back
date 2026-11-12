<?php

namespace App\Enums;

/**
 * Status values for agent-created cards.
 */
enum AgentCardStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';

    /**
     * Check if the card status is active.
     */
    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
