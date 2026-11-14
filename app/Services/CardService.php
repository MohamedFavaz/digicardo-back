<?php

namespace App\Services;

use App\Enums\CardCategory;
use App\Enums\CardStatus;
use App\Exceptions\ConflictException;
use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CardService
{
    public function __construct(
        protected PublicProfileCacheService $cacheService
    ) {}

    /**
     * List all cards for the user with optional filtering.
     */
    public function listForUser(User $user, array $filters = []): Collection
    {
        $query = Profile::where('user_id', $user->id)
            ->withCount('blocks')
            ->orderBy('is_primary', 'desc')
            ->orderBy('created_at', 'desc');

        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('card_title', 'like', $search)
                    ->orWhere('display_name', 'like', $search)
                    ->orWhere('username', 'like', $search);
            });
        }

        return $query->get();
    }

    /**
     * Create a new card for the user.
     */
    public function createForUser(User $user, array $data): Profile
    {
        return DB::transaction(function () use ($user, $data) {
            // Check if user already has any cards
            $isFirstCard = !Profile::where('user_id', $user->id)->exists();

            // Resolve unique username
            $username = !empty($data['username'])
                ? UsernameService::normalize($data['username'])
                : $this->generateUniqueUsername($data['card_title'] ?? $data['display_name'] ?? $user->name ?? 'card');

            if (UsernameService::isReserved($username)) {
                throw new ConflictException('This username is reserved and cannot be claimed.');
            }

            $templateId = $data['template_id'] ?? 'vcard';
            $category = $data['category'] ?? CardCategory::Business->value;
            $cardTitle = $data['card_title'] ?? ($data['display_name'] ?? 'My Digital Card');

            // Default theme tokens based on template
            $defaultTheme = $templateId === 'botanical' ? [
                'color_background' => '#f4fbf7',
                'color_surface' => '#ffffff',
                'color_text_primary' => '#1b4332',
                'color_text_secondary' => '#40916c',
                'color_accent' => '#2d6a4f',
                'font_family' => 'inter',
                'button_radius' => 'large',
                'button_style' => 'solid',
                'animation' => 'fade',
            ] : [
                'color_background' => '#f0f4f8',
                'color_surface' => '#ffffff',
                'color_text_primary' => '#1e293b',
                'color_text_secondary' => '#64748b',
                'color_accent' => '#f97316',
                'font_family' => 'poppins',
                'button_radius' => 'large',
                'button_style' => 'solid',
                'animation' => 'none',
            ];

            $card = Profile::create([
                'user_id' => $user->id,
                'username' => $username,
                'card_title' => $cardTitle,
                'category' => $category,
                'status' => $isFirstCard ? CardStatus::Published : CardStatus::Draft,
                'is_primary' => $isFirstCard,
                'display_name' => $data['display_name'] ?? ($user->name ?? 'Creator'),
                'bio' => $data['bio'] ?? 'Welcome to my digital card!',
                'avatar_url' => $data['avatar_url'] ?? $user->avatar_url,
                'cover_url' => $data['cover_url'] ?? null,
                'template_id' => $templateId,
                'theme_tokens' => $data['theme_tokens'] ?? $defaultTheme,
                'is_public' => $isFirstCard,
                'published_at' => $isFirstCard ? now() : null,
                'published_url' => $isFirstCard ? url('/' . $username) : null,
                'version' => 1,
            ]);

            return $card;
        });
    }

    /**
     * Find a card belonging to the user.
     */
    public function findForUser(User $user, string $cardId): Profile
    {
        $card = Profile::where('user_id', $user->id)
            ->where('id', $cardId)
            ->with(['blocks', 'primaryDomain'])
            ->first();

        if (!$card) {
            throw new NotFoundHttpException('Card not found.');
        }

        return $card;
    }

    /**
     * Update card details.
     */
    public function update(Profile $card, array $data): Profile
    {
        if (isset($data['version']) && (int) $data['version'] !== (int) $card->version) {
            throw new ConflictException('Card has been modified by another request. Please refresh and try again.', $card->version);
        }

        $oldUsername = $card->username;

        DB::transaction(function () use ($card, $data) {
            $updateData = [];

            if (isset($data['card_title'])) $updateData['card_title'] = $data['card_title'];
            if (isset($data['category'])) $updateData['category'] = $data['category'];
            if (isset($data['status'])) $updateData['status'] = $data['status'];
            if (isset($data['display_name'])) $updateData['display_name'] = $data['display_name'];
            if (isset($data['bio'])) $updateData['bio'] = $data['bio'];
            if (isset($data['avatar_url'])) $updateData['avatar_url'] = $data['avatar_url'];
            if (isset($data['cover_url'])) $updateData['cover_url'] = $data['cover_url'];
            if (isset($data['template_id'])) $updateData['template_id'] = $data['template_id'];
            if (isset($data['theme_tokens'])) $updateData['theme_tokens'] = $data['theme_tokens'];
            if (isset($data['is_public'])) $updateData['is_public'] = $data['is_public'];
            if (isset($data['seo_title'])) $updateData['seo_title'] = $data['seo_title'];
            if (isset($data['seo_description'])) $updateData['seo_description'] = $data['seo_description'];

            if (!empty($data['username']) && $data['username'] !== $card->username) {
                $newUsername = UsernameService::normalize($data['username']);
                if (UsernameService::isReserved($newUsername)) {
                    throw new ConflictException('This username is reserved and cannot be claimed.');
                }
                $updateData['username'] = $newUsername;
                $updateData['published_url'] = url('/' . $newUsername);
            }

            $updateData['version'] = $card->version + 1;
            $card->update($updateData);
        });

        $card->refresh();

        // Invalidate public caches if needed
        $this->cacheService->invalidateProfile($oldUsername);
        if ($card->username !== $oldUsername) {
            $this->cacheService->invalidateProfile($card->username);
        }

        return $card;
    }

    /**
     * Publish a card and generate its live public link.
     */
    public function publish(Profile $card): Profile
    {
        $card->update([
            'status' => CardStatus::Published,
            'is_public' => true,
            'published_at' => now(),
            'published_url' => url('/' . $card->username),
            'version' => $card->version + 1,
        ]);

        $this->cacheService->invalidateProfile($card);

        return $card->fresh();
    }

    /**
     * Unpublish a card (set back to draft).
     */
    public function unpublish(Profile $card): Profile
    {
        $card->update([
            'status' => CardStatus::Draft,
            'is_public' => false,
            'version' => $card->version + 1,
        ]);

        $this->cacheService->invalidateProfile($card);

        return $card->fresh();
    }

    /**
     * Set a card as the user's primary card.
     */
    public function setPrimary(User $user, Profile $card): Profile
    {
        DB::transaction(function () use ($user, $card) {
            Profile::where('user_id', $user->id)
                ->where('id', '!=', $card->id)
                ->update(['is_primary' => false]);

            $card->update([
                'is_primary' => true,
                'version' => $card->version + 1,
            ]);
        });

        return $card->fresh();
    }

    /**
     * Duplicate a card and all its content blocks.
     */
    public function duplicate(User $user, Profile $sourceCard): Profile
    {
        return DB::transaction(function () use ($user, $sourceCard) {
            $newUsername = $this->generateUniqueUsername($sourceCard->username . '-copy');
            $cardTitle = ($sourceCard->card_title ?: $sourceCard->display_name ?: 'Card') . ' (Copy)';

            $newCard = Profile::create([
                'user_id' => $user->id,
                'username' => $newUsername,
                'card_title' => $cardTitle,
                'category' => $sourceCard->category ?? CardCategory::Business,
                'status' => CardStatus::Draft,
                'is_primary' => false,
                'display_name' => $sourceCard->display_name,
                'bio' => $sourceCard->bio,
                'avatar_url' => $sourceCard->avatar_url,
                'cover_url' => $sourceCard->cover_url,
                'template_id' => $sourceCard->template_id,
                'theme_tokens' => $sourceCard->theme_tokens,
                'is_public' => false,
                'seo_title' => $sourceCard->seo_title,
                'seo_description' => $sourceCard->seo_description,
                'version' => 1,
            ]);

            // Clone all blocks
            $blocks = ProfileBlock::where('profile_id', $sourceCard->id)->get();
            foreach ($blocks as $block) {
                ProfileBlock::create([
                    'profile_id' => $newCard->id,
                    'type' => $block->type,
                    'sort_order' => $block->sort_order,
                    'config' => $block->config,
                    'is_visible' => $block->is_visible,
                ]);
            }

            return $newCard->load('blocks');
        });
    }

    /**
     * Delete a card safely.
     */
    public function delete(User $user, Profile $card): bool
    {
        return DB::transaction(function () use ($user, $card) {
            $wasPrimary = $card->is_primary;

            $card->delete();

            // If the deleted card was primary, promote the most recent card to primary
            if ($wasPrimary) {
                $nextCard = Profile::where('user_id', $user->id)
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($nextCard) {
                    $nextCard->update(['is_primary' => true]);
                }
            }

            $this->cacheService->invalidateProfile($card->username);

            return true;
        });
    }

    /**
     * Generate a unique username slug based on a seed string.
     */
    protected function generateUniqueUsername(string $seed): string
    {
        $base = UsernameService::normalize(Str::slug($seed) ?: 'card');
        if (strlen($base) < 3) {
            $base = 'card-' . $base;
        }

        $candidate = substr($base, 0, 24);
        $counter = 1;

        while (Profile::where('username', $candidate)->exists() || UsernameService::isReserved($candidate)) {
            $suffix = '-' . $counter;
            $candidate = substr($base, 0, 30 - strlen($suffix)) . $suffix;
            $counter++;
        }

        return $candidate;
    }
}
