<?php

namespace App\Services\Observability;

use App\Contracts\ErrorTrackingProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class LogErrorTrackingProvider implements ErrorTrackingProviderInterface
{
    public function captureException(Throwable $exception, array $context = []): ?string
    {
        $id = (string) Str::uuid();

        Log::error('[ErrorTracker] ' . $exception->getMessage(), array_merge(
            $this->sanitizeContext($context),
            [
                'event_id' => $id,
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]
        ));

        return $id;
    }

    public function captureMessage(string $message, string $level = 'info', array $context = []): ?string
    {
        $id = (string) Str::uuid();

        Log::log($level, '[ErrorTracker] ' . $message, array_merge(
            $this->sanitizeContext($context),
            ['event_id' => $id]
        ));

        return $id;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
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
