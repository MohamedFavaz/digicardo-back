<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Cleans a theme token payload before it is stored on a profile.
 *
 * The core tokens (colours, font, button radius/style, animation) are checked against
 * the same allowlists as UpdateAppearanceRequest, so callers that skip that request
 * (e.g. agent card creation) get the same guarantees.
 *
 * `custom_options` is a template-specific bag that still holds card content until the
 * typed `profiles.content` column lands (see docs/editor-redesign-plan.md, ADR-030).
 * Until then this class enforces AGENTS.md rule 3 on it:
 *  - URL values may only use http, https, mailto or tel, or be scheme-less
 *    (bare domain, relative path, in-page anchor). Anything else — javascript:, data:,
 *    vbscript:, file:, … — is replaced with an empty string.
 *  - Keys ending in `_color` (and every value under `custom_colors`) must be hex colours;
 *    other values are dropped.
 *  - Strings, lists, nesting depth and total size are capped.
 */
class ThemeTokenSanitizer
{
    /** Top-level keys a theme token payload may contain. */
    public const CORE_KEYS = [
        'color_background',
        'color_surface',
        'color_text_primary',
        'color_text_secondary',
        'color_accent',
        'font_family',
        'button_radius',
        'button_style',
        'animation',
    ];

    public const ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public const MAX_STRING_LENGTH = 4000;

    public const MAX_LIST_ITEMS = 100;

    public const MAX_DEPTH = 6;

    public const MAX_CUSTOM_OPTIONS_BYTES = 262144; // 256 KB

    /** Keys whose children are all URLs (e.g. social_urls.instagram). */
    private const URL_BAGS = ['social_urls'];

    /** Keys whose children are all hex colours (e.g. custom_colors.primary). */
    private const COLOR_BAGS = ['custom_colors'];

    private readonly TemplateManifestService $manifests;

    public function __construct(?TemplateManifestService $manifests = null)
    {
        $this->manifests = $manifests ?? new TemplateManifestService();
    }

    /**
     * @param  array<string, mixed>  $tokens      Raw theme token payload.
     * @param  array<string, mixed>  $defaults    Fallback values for invalid core tokens (e.g. template defaults).
     * @param  string|null           $templateId  When given, `template_style` and `preset` are checked
     *                                            against that template's manifest (ADR-031).
     * @return array<string, mixed>
     *
     * @throws ValidationException When custom_options is larger than MAX_CUSTOM_OPTIONS_BYTES.
     */
    public function sanitize(array $tokens, array $defaults = [], ?string $templateId = null): array
    {
        $clean = [];

        foreach (self::CORE_KEYS as $key) {
            $value = $tokens[$key] ?? null;
            if ($this->isValidCoreToken($key, $value)) {
                $clean[$key] = $value;
            } elseif (array_key_exists($key, $defaults) && $this->isValidCoreToken($key, $defaults[$key])) {
                $clean[$key] = $defaults[$key];
            }
        }

        // Read by PublicProfileResource to hide the "Made with Digicardo" badge.
        if (array_key_exists('hide_branding', $tokens)) {
            $clean['hide_branding'] = (bool) $tokens['hide_branding'];
        }

        if ($templateId !== null) {
            if (isset($tokens['template_style']) && is_array($tokens['template_style'])) {
                $clean['template_style'] = $this->manifests->sanitizeStyle($templateId, $tokens['template_style']);
            }
            if (isset($tokens['preset']) && is_string($tokens['preset'])
                && in_array($tokens['preset'], $this->manifests->presetIds($templateId), true)) {
                $clean['preset'] = $tokens['preset'];
            }
        }

        if (isset($tokens['custom_options']) && is_array($tokens['custom_options'])) {
            $encoded = json_encode($tokens['custom_options']);
            if ($encoded !== false && strlen($encoded) > self::MAX_CUSTOM_OPTIONS_BYTES) {
                throw ValidationException::withMessages([
                    'theme_tokens.custom_options' => ['Your card has too much data to save. Remove some photos, products or services and try again.'],
                ]);
            }

            $clean['custom_options'] = $this->sanitizeArray($tokens['custom_options'], null, 1);
        }

        return $clean;
    }

    /**
     * Returns the URL unchanged when its scheme is allowed (or it has none), otherwise ''.
     */
    public function sanitizeUrl(string $url): string
    {
        $candidate = trim($url);
        if ($candidate === '') {
            return '';
        }

        // Browsers ignore control characters and whitespace inside a scheme ("java\tscript:"),
        // so strip them before deciding which scheme the URL really has.
        $probe = preg_replace('/[\x00-\x20\x7F]+/', '', $candidate) ?? '';

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $matches) === 1) {
            $scheme = strtolower($matches[1]);

            // "example.com:8080/path" — a host with a port, not a scheme.
            if (str_contains($scheme, '.')) {
                return $candidate;
            }

            return in_array($scheme, self::ALLOWED_URL_SCHEMES, true) ? $candidate : '';
        }

        return $candidate;
    }

    /**
     * True when the value is an absolute http(s) URL or a site-relative path — the only
     * forms allowed for images that are copied into profiles.avatar_url / cover_url.
     */
    public function isWebUrl(mixed $value): bool
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        $value = trim($value);

        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return true;
        }

        return preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $value) === 1;
    }

    private function isValidCoreToken(string $key, mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return match ($key) {
            'font_family' => in_array($value, TemplateService::FONT_ALLOWLIST, true),
            'button_radius' => in_array($value, TemplateService::BUTTON_RADIUS, true),
            'button_style' => in_array($value, TemplateService::BUTTON_STYLES, true),
            'animation' => in_array($value, TemplateService::ANIMATIONS, true),
            default => preg_match(TemplateService::HEX_COLOR_REGEX, $value) === 1,
        };
    }

    /**
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    private function sanitizeArray(array $input, ?string $parentKey, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        if (array_is_list($input)) {
            $items = array_slice($input, 0, self::MAX_LIST_ITEMS);
            $out = [];
            foreach ($items as $item) {
                // List items inherit their meaning from the list's key (e.g. banner_images[] are URLs).
                $clean = $this->sanitizeValue($item, $parentKey, $parentKey, $depth);
                if ($clean !== null) {
                    $out[] = $clean;
                }
            }

            return $out;
        }

        $out = [];
        foreach ($input as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $key) !== 1) {
                continue;
            }

            $clean = $this->sanitizeValue($value, $key, $parentKey, $depth);
            if ($clean !== null || $value === null) {
                $out[$key] = $clean;
            }
        }

        return $out;
    }

    /**
     * Returns the cleaned value, or null when it must be dropped.
     */
    private function sanitizeValue(mixed $value, ?string $key, ?string $parentKey, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->sanitizeArray($value, $key, $depth + 1);
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = mb_substr($value, 0, self::MAX_STRING_LENGTH);

        if ($this->isColorKey($key, $parentKey)) {
            return preg_match(TemplateService::HEX_COLOR_REGEX, $value) === 1 ? $value : null;
        }

        if ($this->isUrlKey($key, $parentKey)) {
            return $this->sanitizeUrl($value);
        }

        return $value;
    }

    private function isColorKey(?string $key, ?string $parentKey): bool
    {
        if ($parentKey !== null && in_array($parentKey, self::COLOR_BAGS, true)) {
            return true;
        }

        return $key !== null && ($key === 'color' || str_ends_with($key, '_color'));
    }

    private function isUrlKey(?string $key, ?string $parentKey): bool
    {
        if ($parentKey !== null && in_array($parentKey, self::URL_BAGS, true)) {
            return true;
        }

        if ($key === null) {
            return false;
        }

        $key = strtolower($key);

        return $key === 'url'
            || $key === 'href'
            || str_ends_with($key, '_url')
            || str_ends_with($key, '_urls')
            || str_ends_with($key, 'images');
    }
}
