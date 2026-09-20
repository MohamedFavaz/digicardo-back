<?php

namespace App\Exceptions;

use App\Enums\FeatureKey;
use Exception;

class FeatureNotAvailableException extends Exception
{
    public function __construct(
        protected FeatureKey|string $feature,
        protected string $planCode,
        string $message = 'This feature is not available on your current plan. Please upgrade to unlock.',
        int $code = 403
    ) {
        parent::__construct($message, $code);
    }

    public function getFeature(): string
    {
        return $this->feature instanceof FeatureKey ? $this->feature->value : (string) $this->feature;
    }

    public function getPlanCode(): string
    {
        return $this->planCode;
    }

    /**
     * Standardized details payload for API responses.
     *
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'feature' => $this->getFeature(),
            'plan' => $this->getPlanCode(),
        ];
    }
}
