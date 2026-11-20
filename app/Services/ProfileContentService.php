<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads and writes the typed card content (`profiles.content`, ADR-030).
 *
 * - Profiles created before the content model have `content = null`; for them the content
 *   is derived from the legacy theme blob on read, and stored on the first write.
 * - Writes replace whole parts ("identity", "contact", …) under optimistic locking and keep
 *   profiles.display_name / bio / avatar_url / cover_url in sync for SEO, sharing and admin.
 */
class ProfileContentService
{
    public function __construct(
        protected LegacyContentMapper $mapper,
        protected PublicProfileCacheService $cacheService,
        protected TemplateManifestService $manifests
    ) {}

    /**
     * The profile's content in its full normalized shape.
     *
     * @return array<string, mixed>
     */
    public function contentFor(Profile $profile): array
    {
        if (is_array($profile->content)) {
            return CardContentSchema::normalize($profile->content);
        }

        return $this->mapper->map($profile)['content'];
    }

    /**
     * Replace the given parts of the owner's content.
     *
     * @param  array<string, mixed>  $parts  Validated parts keyed by CardContentSchema::PARTS.
     *
     * @throws ConflictException When $version is stale (another tab or device saved first).
     */
    public function updateParts(User $user, int $version, array $parts): Profile
    {
        $profile = $user->profile;
        if (!$profile) {
            throw new NotFoundHttpException('Profile not found for this user.');
        }

        $updated = DB::transaction(function () use ($profile, $version, $parts) {
            $locked = Profile::where('id', $profile->id)->lockForUpdate()->firstOrFail();

            if ($version !== (int) $locked->version) {
                throw new ConflictException(
                    'Your card was changed in another tab or device. Reload to get the latest version.',
                    (int) $locked->version
                );
            }

            $content = is_array($locked->content)
                ? CardContentSchema::normalize($locked->content)
                : $this->materialize($locked);

            foreach ($parts as $part => $value) {
                if (in_array($part, CardContentSchema::PARTS, true)) {
                    $content[$part] = $value;
                }
            }

            $this->store($locked, CardContentSchema::normalize($content));
            $locked->version = ((int) $locked->version) + 1;
            $locked->save();

            return $locked;
        });

        $this->cacheService->invalidateProfile($updated);

        return $updated;
    }

    /**
     * First conversion of a legacy profile: derive the content, back up the old theme blob,
     * add the template style, and hide the contact/social blocks that were lifted into the
     * content so they don't show twice. Must run inside a transaction on a locked row; the
     * caller saves the profile.
     *
     * @return array<string, mixed> The derived content (not yet stored).
     */
    public function materialize(Profile $profile): array
    {
        $result = $this->mapper->map($profile);

        $tokens = is_array($profile->theme_tokens) ? $profile->theme_tokens : [];
        if ($profile->theme_tokens_legacy === null) {
            $profile->theme_tokens_legacy = $tokens;
        }
        if (!isset($tokens['template_style'])) {
            $profile->theme_tokens = $this->styleTokens((string) $profile->template_id, $tokens, $result['template_style']);
        }

        if ($result['lifted_block_ids'] !== []) {
            ProfileBlock::where('profile_id', $profile->id)
                ->whereIn('id', $result['lifted_block_ids'])
                ->update(['is_visible' => false]);
        }

        return $result['content'];
    }

    /**
     * Moves legacy style values into the theme. Botanical cards kept their colours in a named
     * palette; that palette becomes the theme colours (plus any custom_colors overrides) and
     * the `preset`, so the card looks the same after conversion.
     *
     * @param  array<string, mixed>  $tokens
     * @param  array<string, string|bool>  $legacyStyle
     * @return array<string, mixed>
     */
    private function styleTokens(string $templateId, array $tokens, array $legacyStyle): array
    {
        $palette = $legacyStyle['palette'] ?? null;
        unset($legacyStyle['palette']);

        if (is_string($palette)) {
            foreach (($this->manifests->find($templateId)['presets'] ?? []) as $preset) {
                if (($preset['id'] ?? null) === $palette) {
                    $tokens = array_merge($tokens, $preset['tokens'] ?? []);
                    $tokens['preset'] = $palette;
                    break;
                }
            }
            $custom = $tokens['custom_options']['custom_colors'] ?? [];
            $overrides = [
                'background' => 'color_background',
                'primary' => 'color_text_primary',
                'secondary' => 'color_text_secondary',
                'accent' => 'color_accent',
            ];
            foreach ($overrides as $from => $to) {
                if (is_array($custom) && is_string($custom[$from] ?? null) && preg_match(TemplateService::HEX_COLOR_REGEX, $custom[$from]) === 1) {
                    $tokens[$to] = $custom[$from];
                }
            }
        }

        $tokens['template_style'] = $this->manifests->sanitizeStyle($templateId, $legacyStyle);

        return $tokens;
    }

    /**
     * Writes content and syncs the profile columns other features read.
     *
     * @param  array<string, mixed>  $content  Normalized content.
     */
    public function store(Profile $profile, array $content): void
    {
        $profile->content = $content;

        $identity = $content['identity'];
        if ($identity['name'] !== '') {
            $profile->display_name = mb_substr($identity['name'], 0, 100);
        }
        $profile->bio = $identity['bio'] !== '' ? mb_substr($identity['bio'], 0, 500) : null;
        $profile->avatar_url = $identity['avatar_url'] !== '' ? $identity['avatar_url'] : null;
        $profile->cover_url = $identity['cover_images'][0] ?? null;
    }
}
