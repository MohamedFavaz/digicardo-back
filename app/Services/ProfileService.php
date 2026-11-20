<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\ConflictException;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProfileService
{
    /**
     * Templates available on the Free plan. All other templates require AdvancedTemplates entitlement.
     */
    public const FREE_TEMPLATES = ['vcard', 'botanical', 'showcase', 'poster', 'motion'];

    public function __construct(
        protected FeatureEntitlementService $entitlementService,
        protected PublicProfileCacheService $cacheService,
        protected ThemeTokenSanitizer $themeSanitizer
    ) {}

    /**
     * Retrieve the profile for an authenticated user, auto-initializing a default profile if none exists.
     */
    public function getForUser(User $user): Profile
    {
        $profile = $user->profile;

        if ($profile) {
            return $profile;
        }

        // Partner (agent) accounts make cards for customers; they don't get a public card of their
        // own just because a customer-dashboard page asked for "my profile" (e.g. while switching
        // back from "Customize card").
        $role = $user->role instanceof \App\Enums\UserRole ? $user->role->value : (string) $user->role;
        if ($role === 'agent') {
            throw new NotFoundHttpException('Partner accounts do not have their own card.');
        }

        // Auto-initialize default profile
        return DB::transaction(function () use ($user) {
            $base = UsernameService::fromName($user->name ?: 'user');
            $candidate = $base;
            $counter = 2;

            while (Profile::where('username', $candidate)->exists() || UsernameService::isReserved($candidate)) {
                $suffix = '-' . $counter;
                $candidate = rtrim(substr($base, 0, 30 - strlen($suffix)), '-') . $suffix;
                $counter++;
            }

            return Profile::create([
                'user_id' => $user->id,
                'username' => $candidate,
                'display_name' => $user->name ?: 'Creator',
                // No placeholder bio: empty content is hidden on the card (ADR-030).
                'bio' => null,
                'template_id' => 'vcard',
                'theme_tokens' => TemplateService::APPROVED_TEMPLATES['vcard']['default_theme'],
                'content' => CardContentSchema::starter($user->name ?: 'Creator'),
                'is_public' => true,
                'version' => 1,
            ]);
        });
    }

    /**
     * Create a new profile for the user.
     *
     * @param User $user
     * @param array<string, mixed> $data
     * @return Profile
     * @throws ConflictException
     */
    public function createForUser(User $user, array $data): Profile
    {
        // Enforce 1 User = 1 Profile rule
        if ($user->profile()->exists()) {
            throw new ConflictException('User already has an existing profile.');
        }

        // Enforce plan profile count limit
        $this->entitlementService->assertWithinLimit($user, FeatureKey::ProfileCount);

        $username = UsernameService::normalize($data['username']);

        if (UsernameService::isReserved($username)) {
            throw new ConflictException('This username is reserved and cannot be claimed.');
        }

        try {
            return DB::transaction(function () use ($user, $data, $username) {
                return Profile::create([
                    'user_id' => $user->id,
                    'username' => $username,
                    'display_name' => $data['display_name'] ?? null,
                    'bio' => $data['bio'] ?? null,
                    'avatar_url' => $data['avatar_url'] ?? null,
                    'template_id' => $data['template_id'] ?? 'vcard',
                    'is_public' => $data['is_public'] ?? true,
                    'seo_title' => $data['seo_title'] ?? null,
                    'seo_description' => $data['seo_description'] ?? null,
                    'content' => CardContentSchema::starter((string) ($data['display_name'] ?? $user->name ?? ''), $data['bio'] ?? null),
                    'version' => 1,
                ]);
            });
        } catch (QueryException $e) {
            // Handle race condition on duplicate username or user_id
            if (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062) {
                throw new ConflictException('This username or user profile already exists.');
            }
            throw $e;
        }
    }

    /**
     * Update an existing user profile.
     *
     * @param User $user
     * @param array<string, mixed> $data
     * @return Profile
     * @throws ConflictException|NotFoundHttpException
     */
    public function updateForUser(User $user, array $data): Profile
    {
        $profile = $user->profile;

        if (!$profile) {
            throw new NotFoundHttpException('Profile not found for this user.');
        }

        if (isset($data['username'])) {
            $data['username'] = UsernameService::normalize($data['username']);
            if (UsernameService::isReserved($data['username'])) {
                throw new ConflictException('This username is reserved and cannot be claimed.');
            }
        }

        try {
            $updated = DB::transaction(function () use ($profile, $data) {
                $profile->fill($data);
                $profile->version = ((int) $profile->version) + 1;
                $profile->save();

                return $profile;
            });

            $this->cacheService->invalidateProfile($updated);

            return $updated;
        } catch (QueryException $e) {
            // Handle race condition on unique username constraint violation
            if (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062) {
                throw new ConflictException('This username is already taken. Please choose another.');
            }
            throw $e;
        }
    }

    /**
     * Update profile template and theme tokens with optimistic concurrency control.
     *
     * @param User $user
     * @param array<string, mixed> $data
     * @return Profile
     * @throws ConflictException|NotFoundHttpException
     */
    public function updateAppearanceForUser(User $user, array $data): Profile
    {
        $profile = $user->profile;

        if (!$profile) {
            throw new NotFoundHttpException('Profile not found for this user.');
        }

        $result = DB::transaction(function () use ($user, $profile, $data) {
            $lockedProfile = Profile::where('id', $profile->id)->lockForUpdate()->firstOrFail();

            // Optimistic concurrency (AGENTS.md rule 8): a stale version means another tab or
            // device saved first. The client reloads the current version and retries.
            $clientVersion = (int) ($data['version'] ?? 0);
            if ($clientVersion !== (int) $lockedProfile->version) {
                throw new ConflictException(
                    'Your card was changed in another tab or device. Reload to get the latest version.',
                    (int) $lockedProfile->version
                );
            }

            if (isset($data['template_id'])) {
                $templateId = (string) $data['template_id'];
                if (!in_array($templateId, self::FREE_TEMPLATES, true)) {
                    $this->entitlementService->assertCan($user, FeatureKey::AdvancedTemplates);
                }
                $lockedProfile->template_id = $templateId;
            }

            if (isset($data['theme_tokens']) && is_array($data['theme_tokens'])) {
                // Never persist the raw payload: unsafe URL schemes, non-hex colours and
                // unknown top-level keys are removed here (AGENTS.md rule 3).
                $templateDefaults = TemplateService::APPROVED_TEMPLATES[$lockedProfile->template_id]['default_theme'] ?? [];
                $tokens = $this->themeSanitizer->sanitize($data['theme_tokens'], $templateDefaults, (string) $lockedProfile->template_id);
                $lockedProfile->theme_tokens = $tokens;

                // Keep profiles.avatar_url / cover_url in sync — only with real web URLs.
                // Converted cards keep identity in `content` (ADR-030); never sync from legacy options.
                $customOpts = is_array($lockedProfile->content) ? [] : ($tokens['custom_options'] ?? []);
                if (!empty($customOpts['remove_avatar'])) {
                    $lockedProfile->avatar_url = null;
                } elseif ($this->themeSanitizer->isWebUrl($customOpts['profile_image_url'] ?? null)) {
                    $lockedProfile->avatar_url = trim($customOpts['profile_image_url']);
                } elseif ($this->themeSanitizer->isWebUrl($customOpts['custom_avatar_url'] ?? null)) {
                    $lockedProfile->avatar_url = trim($customOpts['custom_avatar_url']);
                }

                if ($this->themeSanitizer->isWebUrl($customOpts['banner_image_url'] ?? null)) {
                    $lockedProfile->cover_url = trim($customOpts['banner_image_url']);
                } elseif (!empty($customOpts['banner_images']) && is_array($customOpts['banner_images'])) {
                    $firstBanner = reset($customOpts['banner_images']);
                    if ($this->themeSanitizer->isWebUrl($firstBanner)) {
                        $lockedProfile->cover_url = trim($firstBanner);
                    }
                }

                // Same limits as UpdateProfileRequest (display_name 100, bio 500).
                if (!empty($customOpts['display_name_override']) && is_string($customOpts['display_name_override'])) {
                    $lockedProfile->display_name = mb_substr(trim($customOpts['display_name_override']), 0, 100);
                }

                if (isset($customOpts['bio_override']) && is_string($customOpts['bio_override'])) {
                    $lockedProfile->bio = mb_substr(trim($customOpts['bio_override']), 0, 500);
                }
            }

            $lockedProfile->version = ((int) $lockedProfile->version) + 1;
            $lockedProfile->save();

            return $lockedProfile;
        });

        $this->cacheService->invalidateProfile($result);

        return $result;
    }

    /**
     * Retrieve a public profile by its unique username slug with eager-loaded active blocks.
     * Cached for 300 seconds; automatically invalidated upon profile/block mutations.
     */
    public function getPublicProfile(string $username): ?Profile
    {
        $normalized = UsernameService::normalize($username);

        return Cache::remember("public_profile:{$normalized}", 300, function () use ($normalized) {
            return Profile::where('username', $normalized)
                ->where('is_public', true)
                ->where(function ($query) {
                    $query->whereNull('moderation_status')
                        ->orWhereIn('moderation_status', ['active', 'under_review']);
                })
                ->whereHas('user', function ($query) {
                    $query->where('status', 'active');
                })
                ->with([
                    'blocks' => function ($query) {
                        $query->where('is_visible', true)->orderBy('sort_order', 'asc');
                    },
                    'primaryDomain',
                    'ogImageMedia',
                    'user.subscriptions.plan',
                ])
                ->first();
        });
    }

    /**
     * Aggregated public profile metrics, embedded in the cached public profile payload.
     *
     * Uses two grouped queries (daily rollups + raw events) plus one contact count instead
     * of one query per metric, so a cache miss stays cheap. As before, each metric uses the
     * larger of the daily rolled-up total and the raw event count.
     *
     * @param Profile $profile
     * @return array{views: int, clicks: int, actions: int, days_live: int, engage: int}
     */
    public function getPublicProfileStats(Profile $profile): array
    {
        return Cache::remember("profile_public_stats:{$profile->id}", 60, function () use ($profile) {
            $viewTypes = [\App\Enums\AnalyticsEventType::ProfileView->value];
            $clickTypes = [
                \App\Enums\AnalyticsEventType::LinkClick->value,
                \App\Enums\AnalyticsEventType::SocialClick->value,
                \App\Enums\AnalyticsEventType::CtaClick->value,
                \App\Enums\AnalyticsEventType::ImageClick->value,
            ];
            $actionTypes = [
                \App\Enums\AnalyticsEventType::CtaClick->value,
                \App\Enums\AnalyticsEventType::WhatsAppClick->value,
                \App\Enums\AnalyticsEventType::PhoneClick->value,
                \App\Enums\AnalyticsEventType::EmailClick->value,
                \App\Enums\AnalyticsEventType::BookingClick->value,
            ];
            $allTypes = array_values(array_unique(array_merge($viewTypes, $clickTypes, $actionTypes)));

            $daily = \App\Models\AnalyticsDailyMetric::query()
                ->toBase()
                ->where('profile_id', $profile->id)
                ->whereIn('event_type', $allTypes)
                ->groupBy('event_type')
                ->selectRaw('event_type, SUM(total_count) as total')
                ->pluck('total', 'event_type');

            $raw = \App\Models\AnalyticsEvent::query()
                ->toBase()
                ->where('profile_id', $profile->id)
                ->whereIn('event_type', $allTypes)
                ->groupBy('event_type')
                ->selectRaw('event_type, COUNT(*) as total')
                ->pluck('total', 'event_type');

            $sumFor = function ($totals, array $types): int {
                $sum = 0;
                foreach ($types as $type) {
                    $sum += (int) ($totals[$type] ?? 0);
                }
                return $sum;
            };
            $metric = fn (array $types): int => max($sumFor($daily, $types), $sumFor($raw, $types));

            $totalViews = $metric($viewTypes);
            $totalClicks = $metric($clickTypes);
            $contactCount = (int) \App\Models\ContactSubmission::where('profile_id', $profile->id)->count();
            $totalActions = $metric($actionTypes) + $contactCount;

            $createdAt = $profile->created_at ? \Carbon\Carbon::parse($profile->created_at) : \Carbon\Carbon::now();
            $daysLive = max(1, (int) $createdAt->diffInDays(\Carbon\Carbon::now()) + 1);

            return [
                'views' => $totalViews,
                'clicks' => $totalClicks,
                'actions' => $totalActions,
                'days_live' => $daysLive,
                'engage' => $totalViews + $totalClicks + $totalActions,
            ];
        });
    }
}
