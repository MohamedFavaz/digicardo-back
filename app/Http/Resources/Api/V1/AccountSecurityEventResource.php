<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\AccountSecurityEventType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountSecurityEventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $eventType = $this->event_type instanceof AccountSecurityEventType
            ? $this->event_type
            : AccountSecurityEventType::tryFrom((string) $this->event_type);

        return [
            'id' => $this->id,
            'event_type' => $eventType?->value ?? (string) $this->event_type,
            'description' => $eventType?->label() ?? 'Security Activity',
            'metadata' => $this->sanitizeMetadata($this->metadata),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Ensure metadata is sanitized and never exposes sensitive credentials.
     *
     * @param mixed $metadata
     * @return array<string, mixed>
     */
    protected function sanitizeMetadata(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        $forbidden = ['password', 'token', 'secret', 'key', 'ip', 'ip_address', 'hash'];

        return array_filter($metadata, function ($key) use ($forbidden) {
            return ! in_array(strtolower((string) $key), $forbidden, true);
        }, ARRAY_FILTER_USE_KEY);
    }
}
