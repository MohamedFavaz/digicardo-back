<?php

namespace App\Contracts;

use Throwable;

interface ErrorTrackingProviderInterface
{
    /**
     * Capture and track an exception with sanitized contextual data.
     *
     * @param Throwable $exception
     * @param array<string, mixed> $context
     * @return string|null An event ID or reference identifier if generated
     */
    public function captureException(Throwable $exception, array $context = []): ?string;

    /**
     * Capture a structured operational message or alert.
     *
     * @param string $message
     * @param string $level ('debug', 'info', 'warning', 'error', 'critical')
     * @param array<string, mixed> $context
     * @return string|null An event ID or reference identifier if generated
     */
    public function captureMessage(string $message, string $level = 'info', array $context = []): ?string;

    /**
     * Check if the error tracking provider is active and enabled.
     */
    public function isEnabled(): bool;
}
