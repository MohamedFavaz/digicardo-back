<?php

namespace App\Enums;

enum CardCategory: string
{
    case Business = 'business';
    case Personal = 'personal';
    case Portfolio = 'portfolio';
    case Restaurant = 'restaurant';
    case Freelancer = 'freelancer';
    case Agency = 'agency';
    case Education = 'education';
    case Healthcare = 'healthcare';
    case RealEstate = 'real_estate';
    case Creative = 'creative';
    case Nonprofit = 'nonprofit';
    case Event = 'event';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business',
            self::Personal => 'Personal',
            self::Portfolio => 'Portfolio',
            self::Restaurant => 'Restaurant',
            self::Freelancer => 'Freelancer',
            self::Agency => 'Agency',
            self::Education => 'Education',
            self::Healthcare => 'Healthcare',
            self::RealEstate => 'Real Estate',
            self::Creative => 'Creative',
            self::Nonprofit => 'Nonprofit',
            self::Event => 'Event',
        };
    }
}
