<?php

namespace App\Services;

use App\Enums\ModerationActionType;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ModerationActionService
{
    /**
     * Record a moderation decision.
     *
     * @param User $actor
     * @param string $targetType ('user', 'profile', 'report')
     * @param string $targetId
     * @param ModerationActionType|string $actionType
     * @param string|null $reason
     * @param string|null $internalNotes
     * @param array<string, mixed> $metadata
     * @return ModerationAction
     */
    public function recordAction(
        User $actor,
        string $targetType,
        string $targetId,
        ModerationActionType|string $actionType,
        ?string $reason = null,
        ?string $internalNotes = null,
        array $metadata = []
    ): ModerationAction {
        $actionValue = $actionType instanceof ModerationActionType
            ? $actionType->value
            : $actionType;

        return ModerationAction::create([
            'actor_id' => $actor->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action_type' => $actionValue,
            'reason' => $reason,
            'internal_notes' => $internalNotes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * List paginated moderation actions.
     *
     * @param array<string, mixed> $filters
     * @param int $page
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function listActions(array $filters = [], int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = ModerationAction::with('actor:id,name,email,role')
            ->orderBy('created_at', 'desc');

        if (! empty($filters['target_type'])) {
            $query->where('target_type', $filters['target_type']);
        }

        if (! empty($filters['target_id'])) {
            $query->where('target_id', $filters['target_id']);
        }

        if (! empty($filters['action_type'])) {
            $query->where('action_type', $filters['action_type']);
        }

        if (! empty($filters['actor_id'])) {
            $query->where('actor_id', $filters['actor_id']);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
