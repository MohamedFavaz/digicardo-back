<?php

namespace App\Exceptions;

use App\Enums\FeatureKey;
use Exception;

class FeatureLimitExceededException extends Exception
{
    public function __construct(
        protected FeatureKey|string $feature,
        protected int $limit,
        protected int $usage,
        protected string $planCode,
        string $message = 'You have reached the maximum allowed limit for this feature on your current plan.',
        int $code = 403
    ) {
        parent::__construct($message, $code);
    }

    public function getFeature(): string
    {
        return $this->feature instanceof FeatureKey ? $this->feature->value : (string) $this->feature;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getUsage(): int
    {
        return $this->usage;
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
            'limit' => $this->getLimit(),
            'usage' => $this->getUsage(),
            'plan' => $this->getPlanCode(),
        ];
    }
}
