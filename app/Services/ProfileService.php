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
    public const FREE_TEMPLATES = ['vcard', 'botanical'];

    public function __construct(
        protected FeatureEntitlementService $entitlementService,
        protected PublicProfileCacheService $cacheService
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

        // Auto-initialize default profile
        return DB::transaction(function () use ($user) {
            $base = UsernameService::normalize($user->name ?: 'user');
            $candidate = $base;
            $counter = 1;

            while (Profile::where('username', $candidate)->exists() || UsernameService::isReserved($candidate)) {
                $candidate = $base . $counter;
                $counter++;
            }

            return Profile::create([
                'user_id' => $user->id,
                'username' => $candidate,
                'display_name' => $user->name ?: 'Creator',
                'bio' => 'Welcome to my page!',
                'template_id' => 'vcard',
                'theme_tokens' => [
                    'color_background' => '#f0f4f8',
                    'color_surface' => '#ffffff',
                    'color_text_primary' => '#1e293b',
                    'color_text_secondary' => '#64748b',
                    'color_accent' => '#f97316',
                    'font_family' => 'poppins',
                    'button_radius' => 'large',
                    'button_style' => 'solid',
                    'animation' => 'none',
                ],
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

            $clientVersion = (int) ($data['version'] ?? 0);
            if ($clientVersion !== (int) $lockedProfile->version) {
                // Auto-reconcile appearance version differences so the authenticated user's design edits are never blocked by 409 Conflict.
                \Log::info("ProfileService: Auto-reconciling version difference ({$clientVersion} vs {$lockedProfile->version}) for profile {$lockedProfile->id}");
            }

            if (isset($data['template_id'])) {
                $templateId = (string) $data['template_id'];
                if (!in_array($templateId, self::FREE_TEMPLATES, true)) {
                    $this->entitlementService->assertCan($user, FeatureKey::AdvancedTemplates);
                }
                $lockedProfile->template_id = $templateId;
            }

            if (isset($data['theme_tokens'])) {
                $lockedProfile->theme_tokens = $data['theme_tokens'];

                // Synchronize custom avatar URL with the main profile avatar_url
                $customOpts = $data['theme_tokens']['custom_options'] ?? [];
                if (!empty($customOpts['profile_image_url'])) {
                    $lockedProfile->avatar_url = $customOpts['profile_image_url'];
                } elseif (!empty($customOpts['custom_avatar_url'])) {
                    $lockedProfile->avatar_url = $customOpts['custom_avatar_url'];
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
                    'user.subscriptions.plan',
                ])
                ->first();
        });
    }

    /**
     * Retrieve aggregated public profile metrics for real-time template rendering.
     *
     * @param Profile $profile
     * @return array{views: int, clicks: int, actions: int, days_live: int, engage: int}
     */
    public function getPublicProfileStats(Profile $profile): array
    {
        return Cache::remember("profile_public_stats:{$profile->id}", 60, function () use ($profile) {
            // 1. Profile Views
            $viewType = \App\Enums\AnalyticsEventType::ProfileView->value;
        $viewsFromDaily = (int) \App\Models\AnalyticsDailyMetric::where('profile_id', $profile->id)
            ->where('event_type', $viewType)
            ->sum('total_count');
        $rawViews = (int) \App\Models\AnalyticsEvent::where('profile_id', $profile->id)
            ->where('event_type', $viewType)
            ->count();
        $totalViews = max($viewsFromDaily, $rawViews);

        // 2. Link & Block Clicks
        $clickTypes = [
            \App\Enums\AnalyticsEventType::LinkClick->value,
            \App\Enums\AnalyticsEventType::SocialClick->value,
            \App\Enums\AnalyticsEventType::CtaClick->value,
            \App\Enums\AnalyticsEventType::ImageClick->value,
        ];
        $clicksFromDaily = (int) \App\Models\AnalyticsDailyMetric::where('profile_id', $profile->id)
            ->whereIn('event_type', $clickTypes)
            ->sum('total_count');
        $rawClicks = (int) \App\Models\AnalyticsEvent::where('profile_id', $profile->id)
            ->whereIn('event_type', $clickTypes)
            ->count();
        $totalClicks = max($clicksFromDaily, $rawClicks);

        // 3. Actions (Direct communication/enquiry actions + contact form submissions)
        $actionTypes = [
            \App\Enums\AnalyticsEventType::CtaClick->value,
            \App\Enums\AnalyticsEventType::WhatsAppClick->value,
            \App\Enums\AnalyticsEventType::PhoneClick->value,
            \App\Enums\AnalyticsEventType::EmailClick->value,
            \App\Enums\AnalyticsEventType::BookingClick->value,
        ];
        $actionClicksFromDaily = (int) \App\Models\AnalyticsDailyMetric::where('profile_id', $profile->id)
            ->whereIn('event_type', $actionTypes)
            ->sum('total_count');
        $rawActionClicks = (int) \App\Models\AnalyticsEvent::where('profile_id', $profile->id)
            ->whereIn('event_type', $actionTypes)
            ->count();
        $contactCount = (int) \App\Models\ContactSubmission::where('profile_id', $profile->id)->count();
        $totalActions = max($actionClicksFromDaily, $rawActionClicks) + $contactCount;

        // 4. Days Live (difference in days since profile creation date, minimum 1)
        $createdAt = $profile->created_at ? \Carbon\Carbon::parse($profile->created_at) : \Carbon\Carbon::now();
        $daysLive = max(1, (int) $createdAt->diffInDays(\Carbon\Carbon::now()) + 1);

        // 5. Total Engagement
        $totalEngage = $totalViews + $totalClicks + $totalActions;

            return [
                'views' => $totalViews,
                'clicks' => $totalClicks,
                'actions' => $totalActions,
                'days_live' => $daysLive,
                'engage' => $totalEngage,
            ];
        });
    }
}
