<?php

namespace App\Services;

/**
 * The typed card content model stored in `profiles.content` (ADR-030).
 *
 * One shape for every template: a template only decides which parts it shows.
 * The frontend mirror is `frontend/src/lib/zod/content.ts`; keep both in sync
 * (AGENTS.md rule 12). Empty strings mean "not set" and are hidden on the card.
 */
class CardContentSchema
{
    public const SCHEMA_VERSION = 1;

    /** Top-level parts of the content that PATCH /profile/content can replace. */
    public const PARTS = [
        'identity',
        'contact',
        'socials',
        'catalog',
        'payments',
        'gallery',
        'links',
        'documents',
        'actions',
        'layout',
    ];

    /** Card sections whose order and visibility the owner controls (`layout`). */
    public const SECTION_KEYS = [
        'services',
        'products',
        'payments',
        'gallery',
        'links',
        'documents',
        'review',
        'qr',
    ];

    public const SOCIAL_PLATFORMS = [
        'instagram',
        'facebook',
        'linkedin',
        'x',
        'youtube',
        'tiktok',
        'whatsapp',
        'telegram',
        'github',
        'snapchat',
        'pinterest',
        'threads',
        'discord',
        'twitch',
        'spotify',
        'whatsapp_channel',
        'behance',
        'dribbble',
        'medium',
        'reddit',
        'google_business',
        'website',
        'other',
    ];

    public const LIMITS = [
        'cover_images' => 6,
        'badges' => 4,
        'socials' => 25,
        'services' => 50,
        'products' => 50,
        'gallery' => 30,
        'links' => 30,
        'documents' => 10,
    ];

    public const UPI_REGEX = '/^[\w.\-]{2,256}@[a-zA-Z]{2,64}$/';

    public const IFSC_REGEX = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    public const ITEM_ID_REGEX = '/^[A-Za-z0-9_\-]{1,40}$/';

    /**
     * Content for a brand-new card: empty except the name (and bio when given). New profiles
     * start on the content model, so the legacy mapper's old defaults (verified tick on,
     * placeholder bios) never apply to them.
     *
     * @return array<string, mixed>
     */
    public static function starter(string $name, ?string $bio = null): array
    {
        $content = self::empty();
        $content['identity']['name'] = mb_substr(trim($name), 0, 100);
        $content['identity']['bio'] = mb_substr(trim((string) $bio), 0, 500);

        return $content;
    }

    /**
     * A complete, empty content document.
     *
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'identity' => [
                'name' => '',
                'role' => '',
                'company' => '',
                'bio' => '',
                'avatar_url' => '',
                'logo_url' => '',
                'cover_images' => [],
                'category_label' => '',
                'hours' => '',
                'badges' => [],
                'verified_badge' => false,
            ],
            'contact' => [
                'phone' => '',
                'whatsapp' => '',
                'email' => '',
                'website' => '',
                'address' => '',
                'map_url' => '',
            ],
            'socials' => [],
            'catalog' => [
                'services_label' => 'Services',
                'products_label' => 'Products',
                'services' => [],
                'products' => [],
                'enquiry_message' => '',
            ],
            'payments' => [
                'upi_id' => '',
                'upi_name' => '',
                'payment_phone' => '',
                'bank' => [
                    'holder' => '',
                    'bank_name' => '',
                    'account_number' => '',
                    'ifsc' => '',
                ],
            ],
            'gallery' => [
                'title' => '',
                'images' => [],
            ],
            'links' => [],
            'documents' => [],
            'actions' => [
                'save_contact' => true,
                'share' => true,
                'qr' => true,
                'review_url' => '',
                'booking_message' => '',
            ],
            'layout' => self::defaultLayout(),
        ];
    }

    /**
     * @return list<array{key: string, visible: bool}>
     */
    public static function defaultLayout(): array
    {
        return array_map(
            fn (string $key) => ['key' => $key, 'visible' => true],
            self::SECTION_KEYS
        );
    }

    /**
     * Fills missing keys with defaults and drops unknown keys, so every reader can rely on
     * the full shape. It does not validate values; writes go through UpdateProfileContentRequest.
     *
     * @param  array<string, mixed>|null  $content
     * @return array<string, mixed>
     */
    public static function normalize(?array $content): array
    {
        $defaults = self::empty();
        $content ??= [];

        $out = ['schema_version' => self::SCHEMA_VERSION];

        $out['identity'] = self::mergeObject($defaults['identity'], $content['identity'] ?? null);
        $out['identity']['cover_images'] = self::stringList($out['identity']['cover_images'], self::LIMITS['cover_images']);
        $out['identity']['badges'] = self::stringList($out['identity']['badges'], self::LIMITS['badges']);
        $out['identity']['verified_badge'] = (bool) $out['identity']['verified_badge'];

        $out['contact'] = self::mergeObject($defaults['contact'], $content['contact'] ?? null);

        $out['socials'] = self::objectList($content['socials'] ?? null, ['id' => '', 'platform' => 'other', 'url' => ''], self::LIMITS['socials']);

        $catalog = self::mergeObject($defaults['catalog'], $content['catalog'] ?? null);
        $catalog['services'] = self::objectList(
            $content['catalog']['services'] ?? null,
            ['id' => '', 'name' => '', 'description' => '', 'price' => '', 'duration' => '', 'image_url' => ''],
            self::LIMITS['services']
        );
        $catalog['products'] = self::objectList(
            $content['catalog']['products'] ?? null,
            ['id' => '', 'name' => '', 'description' => '', 'price' => '', 'image_url' => '', 'buy_url' => ''],
            self::LIMITS['products']
        );
        $out['catalog'] = $catalog;

        $payments = self::mergeObject($defaults['payments'], $content['payments'] ?? null);
        $payments['bank'] = self::mergeObject($defaults['payments']['bank'], $content['payments']['bank'] ?? null);
        $out['payments'] = $payments;

        $gallery = self::mergeObject($defaults['gallery'], $content['gallery'] ?? null);
        $gallery['images'] = self::stringList($gallery['images'], self::LIMITS['gallery']);
        $out['gallery'] = $gallery;

        $out['links'] = self::objectList(
            $content['links'] ?? null,
            ['id' => '', 'title' => '', 'description' => '', 'url' => '', 'icon' => '', 'featured' => false],
            self::LIMITS['links']
        );
        $out['documents'] = self::objectList(
            $content['documents'] ?? null,
            ['id' => '', 'title' => '', 'description' => '', 'url' => '', 'file_name' => '', 'size_label' => ''],
            self::LIMITS['documents']
        );

        $actions = self::mergeObject($defaults['actions'], $content['actions'] ?? null);
        foreach (['save_contact', 'share', 'qr'] as $flag) {
            $actions[$flag] = (bool) $actions[$flag];
        }
        $out['actions'] = $actions;

        $out['layout'] = self::normalizeLayout($content['layout'] ?? null);

        return $out;
    }

    /**
     * Keeps known section keys once each, in the stored order, and appends any missing
     * keys (visible) so a newly added section type always appears somewhere.
     *
     * @return list<array{key: string, visible: bool}>
     */
    public static function normalizeLayout(mixed $layout): array
    {
        $seen = [];
        $out = [];

        if (is_array($layout)) {
            foreach ($layout as $entry) {
                $key = is_array($entry) ? ($entry['key'] ?? null) : null;
                if (!is_string($key) || !in_array($key, self::SECTION_KEYS, true) || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = ['key' => $key, 'visible' => (bool) ($entry['visible'] ?? true)];
            }
        }

        foreach (self::SECTION_KEYS as $key) {
            if (!isset($seen[$key])) {
                $out[] = ['key' => $key, 'visible' => true];
            }
        }

        return $out;
    }

    /**
     * Every image URL referenced by the content (used to accept unchanged images on save).
     *
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public static function imageUrls(array $content): array
    {
        $urls = [];
        $identity = $content['identity'] ?? [];
        foreach (['avatar_url', 'logo_url'] as $key) {
            if (!empty($identity[$key]) && is_string($identity[$key])) {
                $urls[] = $identity[$key];
            }
        }
        foreach (($identity['cover_images'] ?? []) as $url) {
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }
        foreach (($content['gallery']['images'] ?? []) as $url) {
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }
        foreach (($content['catalog']['services'] ?? []) as $service) {
            if (is_array($service) && !empty($service['image_url']) && is_string($service['image_url'])) {
                $urls[] = $service['image_url'];
            }
        }
        foreach (($content['catalog']['products'] ?? []) as $product) {
            if (is_array($product) && !empty($product['image_url']) && is_string($product['image_url'])) {
                $urls[] = $product['image_url'];
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private static function mergeObject(array $defaults, mixed $value): array
    {
        if (!is_array($value)) {
            return $defaults;
        }

        $out = [];
        foreach ($defaults as $key => $default) {
            $candidate = $value[$key] ?? $default;
            if (is_string($default)) {
                $out[$key] = is_scalar($candidate) ? trim((string) $candidate) : $default;
            } elseif (is_bool($default)) {
                $out[$key] = is_bool($candidate) ? $candidate : (bool) $candidate;
            } else {
                $out[$key] = is_array($candidate) ? $candidate : $default;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, int $max): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_slice(array_values(array_unique($out)), 0, $max);
    }

    /**
     * @param  array<string, mixed>  $shape
     * @return list<array<string, mixed>>
     */
    private static function objectList(mixed $value, array $shape, int $max): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach (array_values($value) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = self::mergeObject($shape, $item);
            if (array_key_exists('id', $row) && ($row['id'] === '' || preg_match(self::ITEM_ID_REGEX, $row['id']) !== 1)) {
                $row['id'] = 'item-' . ($index + 1);
            }
            $out[] = $row;
        }

        return array_slice($out, 0, $max);
    }
}
