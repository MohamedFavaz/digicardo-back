<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class AdminAuditService
{
    /**
     * Log an administrative or governance action.
     *
     * @param User $actor
     * @param string $action
     * @param string $targetType
     * @param string $targetId
     * @param array<string, mixed> $metadata
     * @return AdminAuditLog
     */
    public function log(
        User $actor,
        string $action,
        string $targetType,
        string $targetId,
        array $metadata = []
    ): AdminAuditLog {
        $requestId = request()?->attributes->get('request_id')
            ?? request()?->header('X-Request-ID');

        $ip = request()?->ip();
        $ipHash = $ip ? hash_hmac('sha256', $ip, config('app.key')) : null;

        $sanitizedMeta = $this->sanitizeMetadata($metadata);

        $log = AdminAuditLog::create([
            'actor_id' => $actor->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $sanitizedMeta,
            'request_id' => $requestId,
            'ip_hash' => $ipHash,
            'created_at' => now(),
        ]);

        Log::info("[AdminAudit] Admin {$actor->id} performed {$action} on {$targetType}:{$targetId}", [
            'admin_id' => $actor->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_id' => $requestId,
        ]);

        return $log;
    }

    /**
     * List audit logs with pagination and filters.
     *
     * @param array<string, mixed> $filters
     * @param int $page
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getLogs(array $filters = [], int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = AdminAuditLog::with('actor:id,name,email,role')
            ->orderBy('created_at', 'desc');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['target_type'])) {
            $query->where('target_type', $filters['target_type']);
        }

        if (! empty($filters['actor_id'])) {
            $query->where('actor_id', $filters['actor_id']);
        }

        if (! empty($filters['request_id'])) {
            $query->where('request_id', $filters['request_id']);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Sanitize metadata to exclude passwords, tokens, cookies, secrets, and auth headers.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    protected function sanitizeMetadata(array $metadata): array
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
            'session_id',
            'csrf_token',
        ];

        $sanitized = [];
        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeMetadata($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
