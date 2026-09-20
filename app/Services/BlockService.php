<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\ConflictException;
use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BlockService
{
    private const GATED_BLOCK_TYPES = [
        'gallery' => FeatureKey::GalleryBlocks,
        'video' => FeatureKey::VideoBlocks,
        'music' => FeatureKey::MusicBlocks,
        'booking' => FeatureKey::BookingBlocks,
    ];

    public function __construct(
        protected FeatureEntitlementService $entitlementService,
        protected PublicProfileCacheService $cacheService
    ) {}

    /**
     * Assert that the user is entitled to create or update the specified block type.
     */
    public function assertBlockTypeAllowed(User $user, string $type): void
    {
        if (isset(self::GATED_BLOCK_TYPES[$type])) {
            $this->entitlementService->assertCan($user, self::GATED_BLOCK_TYPES[$type]);
        }
    }

    /**
     * Retrieve all blocks for an owner profile ordered by sort_order.
     *
     * @param Profile $profile
     * @return Collection<int, ProfileBlock>
     */
    public function getForProfile(Profile $profile): Collection
    {
        return $profile->blocks()->orderBy('sort_order', 'asc')->get();
    }

    /**
     * Create a new block for the given profile with safe sequential sort_order.
     *
     * @param Profile $profile
     * @param array<string, mixed> $data
     * @return ProfileBlock
     */
    public function createForProfile(Profile $profile, array $data): ProfileBlock
    {
        // Enforce block type entitlement if user is present
        $user = $profile->user ?? User::find($profile->user_id);
        if ($user) {
            $this->assertBlockTypeAllowed($user, $data['type']);
        }
        $block = DB::transaction(function () use ($profile, $data) {
            // Lock profile to prevent race conditions during sort order assignment
            $lockedProfile = Profile::where('id', $profile->id)->lockForUpdate()->firstOrFail();

            $maxSort = $lockedProfile->blocks()->max('sort_order');
            $nextSort = ($maxSort !== null) ? ((int) $maxSort) + 1 : 0;

            $newBlock = ProfileBlock::create([
                'profile_id' => $lockedProfile->id,
                'type' => $data['type'],
                'config' => $data['config'] ?? [],
                'is_visible' => $data['is_visible'] ?? true,
                'sort_order' => $nextSort,
                'version' => 1,
            ]);

            $lockedProfile->version = ((int) $lockedProfile->version) + 1;
            $lockedProfile->save();

            return $newBlock;
        });

        $this->cacheService->invalidateProfile($profile);

        return $block;
    }

    /**
     * Update a block with optimistic concurrency control.
     *
     * @param ProfileBlock $block
     * @param array<string, mixed> $data
     * @return ProfileBlock
     * @throws ConflictException
     */
    public function update(ProfileBlock $block, array $data): ProfileBlock
    {
        if (isset($data['type'])) {
            $user = $block->profile?->user ?? User::find($block->profile?->user_id);
            if ($user) {
                $this->assertBlockTypeAllowed($user, $data['type']);
            }
        }

        $updatedBlock = DB::transaction(function () use ($block, $data) {
            $lockedBlock = ProfileBlock::where('id', $block->id)->lockForUpdate()->firstOrFail();

            // Optimistic concurrency version check
            $clientVersion = (int) ($data['version'] ?? 0);
            if ($clientVersion !== (int) $lockedBlock->version) {
                throw new ConflictException(
                    'Block was updated by another request. Please reload.',
                    (int) $lockedBlock->version
                );
            }

            if (isset($data['type'])) {
                $lockedBlock->type = $data['type'];
            }

            if (isset($data['config'])) {
                $lockedBlock->config = $data['config'];
            }

            if (isset($data['is_visible'])) {
                $lockedBlock->is_visible = (bool) $data['is_visible'];
            }

            $lockedBlock->version = ((int) $lockedBlock->version) + 1;
            $lockedBlock->save();

            // Increment parent profile version
            $lockedBlock->profile()->increment('version');

            return $lockedBlock;
        });

        if ($block->profile) {
            $this->cacheService->invalidateProfile($block->profile);
        }

        return $updatedBlock;
    }

    /**
     * Delete a block and increment parent profile version.
     *
     * @param ProfileBlock $block
     * @return void
     */
    public function delete(ProfileBlock $block): void
    {
        $profile = $block->profile;

        DB::transaction(function () use ($block, $profile) {
            $block->delete();

            if ($profile) {
                $profile->increment('version');
            }
        });

        if ($profile) {
            $this->cacheService->invalidateProfile($profile);
        }
    }

    /**
     * Reorder profile blocks transactionally with optimistic collection versioning.
     *
     * @param Profile $profile
     * @param array<int, string> $orderedIds
     * @param int $expectedVersion
     * @return array{blocks: Collection<int, ProfileBlock>, version: int}
     * @throws ConflictException|ValidationException
     */
    public function reorder(Profile $profile, array $orderedIds, int $expectedVersion): array
    {
        $result = DB::transaction(function () use ($profile, $orderedIds, $expectedVersion) {
            $lockedProfile = Profile::where('id', $profile->id)->lockForUpdate()->firstOrFail();

            if ((int) $lockedProfile->version !== $expectedVersion) {
                throw new ConflictException(
                    'Profile content was modified by another session. Please reload.',
                    (int) $lockedProfile->version
                );
            }

            $existingBlocks = $lockedProfile->blocks()->get()->keyBy('id');

            // Validate that all ordered IDs belong to this profile and count matches
            if (count($orderedIds) !== $existingBlocks->count()) {
                throw ValidationException::withMessages([
                    'ordered_ids' => ['The reorder payload must include all existing blocks for this profile.'],
                ]);
            }

            foreach ($orderedIds as $index => $id) {
                if (!$existingBlocks->has($id)) {
                    throw ValidationException::withMessages([
                        'ordered_ids' => ["Block ID {$id} does not belong to this profile."],
                    ]);
                }
            }

            // Assign new sort order
            foreach ($orderedIds as $index => $id) {
                $block = $existingBlocks->get($id);
                if ($block && (int) $block->sort_order !== $index) {
                    $block->update(['sort_order' => $index]);
                }
            }

            $lockedProfile->version = ((int) $lockedProfile->version) + 1;
            $lockedProfile->save();

            $updatedBlocks = $lockedProfile->blocks()->orderBy('sort_order', 'asc')->get();

            return [
                'blocks' => $updatedBlocks,
                'version' => (int) $lockedProfile->version,
            ];
        });

        $this->cacheService->invalidateProfile($profile);

        return $result;
    }
}
