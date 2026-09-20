<?php

namespace App\Services\Observability;

use App\Contracts\ErrorTrackingProviderInterface;
use Illuminate\Support\Str;
use Throwable;

class NullErrorTrackingProvider implements ErrorTrackingProviderInterface
{
    /**
     * @var list<array{type: string, message: string, exception_class: string|null, level: string, context: array<string, mixed>, id: string}>
     */
    protected static array $capturedEvents = [];

    /**
     * Capture and track an exception in memory.
     */
    public function captureException(Throwable $exception, array $context = []): ?string
    {
        $id = (string) Str::uuid();

        static::$capturedEvents[] = [
            'type' => 'exception',
            'message' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'level' => 'error',
            'context' => $this->sanitizeContext($context),
            'id' => $id,
        ];

        return $id;
    }

    /**
     * Capture a message in memory.
     */
    public function captureMessage(string $message, string $level = 'info', array $context = []): ?string
    {
        $id = (string) Str::uuid();

        static::$capturedEvents[] = [
            'type' => 'message',
            'message' => $message,
            'exception_class' => null,
            'level' => $level,
            'context' => $this->sanitizeContext($context),
            'id' => $id,
        ];

        return $id;
    }

    /**
     * Provider is disabled in null driver mode.
     */
    public function isEnabled(): bool
    {
        return false;
    }

    /**
     * Get all captured events (useful for tests).
     *
     * @return list<array{type: string, message: string, exception_class: string|null, level: string, context: array<string, mixed>, id: string}>
     */
    public static function getCapturedEvents(): array
    {
        return static::$capturedEvents;
    }

    /**
     * Clear captured events.
     */
    public static function clear(): void
    {
        static::$capturedEvents = [];
    }

    /**
     * Sanitize context to ensure credentials, tokens, and passwords are never tracked.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function sanitizeContext(array $context): array
    {
        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'current_password',
            'token',
            'secret',
            'api_key',
            'authorization',
            'cookie',
            'csrf_token',
            'session_id',
        ];

        $sanitized = [];
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
