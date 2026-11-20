<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\ProfileBlock;
use Illuminate\Support\Collection;

/**
 * Maps the legacy `theme_tokens.custom_options` bag (+ contact blocks) into the typed
 * card content (ADR-030, plan §4.1). Pure: it never writes. The frontend mirror used
 * while profiles still have no `content` is `frontend/src/lib/content/legacy.ts`.
 */
class LegacyContentMapper
{
    /** System placeholder bios that were never written by an owner. */
    public const PLACEHOLDER_BIOS = ['Welcome to my page!', 'Welcome to my LinkForge page!'];

    /** custom_options keys this mapper understands (content, style, or dropped by design). */
    public const KNOWN_KEYS = [
        // identity
        'display_name_override', 'tagline_override', 'bio_override', 'custom_avatar_url',
        'profile_image_url', 'remove_avatar', 'banner_images', 'banner_image_url',
        'category_badge_text', 'show_meta_verified', 'verified_badge', 'company_name',
        'company_logo_url', 'executive_role', 'trust_badges', 'show_company_name',
        'show_executive_role', 'show_bio',
        // contact
        'whatsapp_number', 'address_text', 'map_url', 'website_url', 'social_urls',
        'quick_actions', 'phone', 'whatsapp', 'email', 'website',
        // socials
        'active_action_icons', 'social_links', 'social_position', 'show_socials',
        // catalog
        'services', 'products', 'services_button_text', 'products_button_text',
        'services_enquiry_template', 'products_enquiry_template', 'services_enquiry_btn_text',
        'products_enquiry_btn_text', 'view_service_btn_enabled', 'view_products_btn_enabled',
        'products_url',
        // payments
        'upi_id', 'upi_number', 'payment_phone', 'bank_name', 'bank_account_number',
        'bank_ifsc', 'bank_holder_name', 'pay_now_enabled', 'pay_now_btn_enabled',
        // gallery
        'gallery_enabled', 'gallery_title', 'gallery_images',
        // links & documents
        'content_links', 'drive_block', 'document_block',
        // actions
        'google_review_url', 'review_btn_enabled', 'review_btn_text', 'booking_enabled',
        'booking_whatsapp_message', 'show_save_contact', 'save_contact_enabled', 'show_share',
        'share_profile_enabled', 'show_qr', 'qr_code_enabled', 'qr_title', 'qr_desc',
        'qr_target_url', 'location_btn_enabled',
        // style → theme_tokens.template_style (or retired with the redesign)
        'bg_style', 'color_palette', 'palette', 'custom_colors', 'banner_transition',
        'button_gradient_animation', 'primary_button_color', 'pay_button_color',
        'location_button_color', 'products_button_color', 'services_enquiry_btn_color',
        'products_enquiry_btn_color', 'qr_color_style',
    ];

    /** VCard social keys in the order the old icon grid showed them. */
    private const VCARD_SOCIAL_KEYS = [
        'instagram', 'facebook', 'linkedin', 'twitter', 'youtube', 'tiktok', 'telegram',
        'github', 'snapchat', 'pinterest', 'threads', 'discord', 'twitch', 'spotify',
        'whatsapp_channel', 'behance', 'dribbble',
    ];

    /**
     * @return array{
     *     content: array<string, mixed>,
     *     template_style: array<string, string|bool>,
     *     lifted_block_ids: list<string>,
     *     unmapped_keys: list<string>
     * }
     */
    public function map(Profile $profile, ?Collection $blocks = null): array
    {
        $blocks ??= $profile->blocks()->get();
        $tokens = is_array($profile->theme_tokens) ? $profile->theme_tokens : [];
        $co = is_array($tokens['custom_options'] ?? null) ? $tokens['custom_options'] : [];
        $isBotanical = $profile->template_id === 'botanical';

        $visibleBlocks = $blocks->filter(fn (ProfileBlock $b) => (bool) $b->is_visible)->values();
        $firstBlock = fn (string $type) => $visibleBlocks->first(fn (ProfileBlock $b) => $b->type === $type);
        $lifted = [];

        $content = CardContentSchema::empty();

        // ── identity ────────────────────────────────────────────────────────────────
        $bio = $this->str($co['bio_override'] ?? null) ?: $this->str($co['tagline_override'] ?? null) ?: $this->str($profile->bio);
        if (in_array($bio, self::PLACEHOLDER_BIOS, true) || $this->isHidden($co['show_bio'] ?? null)) {
            $bio = '';
        }

        $avatarKeys = $isBotanical ? ['profile_image_url', 'custom_avatar_url'] : ['custom_avatar_url', 'profile_image_url'];
        $avatar = '';
        if (!$this->truthy($co['remove_avatar'] ?? false)) {
            foreach ($avatarKeys as $key) {
                $avatar = $avatar ?: $this->imageUrl($co[$key] ?? null);
            }
            $avatar = $avatar ?: $this->imageUrl($profile->avatar_url);
        }

        $covers = [];
        if ($cover = $this->imageUrl($co['banner_image_url'] ?? null)) {
            $covers[] = $cover;
        }
        foreach ((array) ($co['banner_images'] ?? []) as $url) {
            if (($clean = $this->imageUrl($url)) !== '') {
                $covers[] = $clean;
            }
        }
        if ($covers === [] && ($cover = $this->imageUrl($profile->cover_url)) !== '') {
            $covers[] = $cover;
        }

        $badges = [];
        foreach ((array) ($co['trust_badges'] ?? []) as $badge) {
            if (is_array($badge) && ($badge['enabled'] ?? true) !== false && ($text = $this->str($badge['text'] ?? null)) !== '') {
                $badges[] = mb_substr($text, 0, 40);
            }
        }

        $verifiedKey = $isBotanical ? 'verified_badge' : 'show_meta_verified';

        $content['identity'] = [
            'name' => mb_substr($this->str($co['display_name_override'] ?? null) ?: $this->str($profile->display_name) ?: (string) $profile->username, 0, 100),
            'role' => $this->isHidden($co['show_executive_role'] ?? null) ? '' : mb_substr($this->str($co['executive_role'] ?? null), 0, 100),
            'company' => $this->isHidden($co['show_company_name'] ?? null) ? '' : mb_substr($this->str($co['company_name'] ?? null), 0, 100),
            'bio' => mb_substr($bio, 0, 500),
            'avatar_url' => $avatar,
            'logo_url' => $this->imageUrl($co['company_logo_url'] ?? null),
            'cover_images' => array_slice(array_values(array_unique($covers)), 0, CardContentSchema::LIMITS['cover_images']),
            'category_label' => mb_substr($this->str($co['category_badge_text'] ?? null), 0, 60),
            'hours' => '',
            'badges' => array_slice($badges, 0, CardContentSchema::LIMITS['badges']),
            'verified_badge' => ($co[$verifiedKey] ?? true) !== false,
        ];

        // ── contact ─────────────────────────────────────────────────────────────────
        $qa = is_array($co['quick_actions'] ?? null) ? $co['quick_actions'] : [];
        $qaOff = fn (string $show, string $item) => ($qa[$show] ?? true) === false || (($qa[$item]['enabled'] ?? true) === false);

        $phoneBlock = $firstBlock('phone');
        $phone = $this->str($qa['phone']['number'] ?? null) ?: $this->str($co['phone'] ?? null);
        if ($phone === '' && $phoneBlock) {
            $phone = $this->str($phoneBlock->config['phone'] ?? null);
            if ($phone !== '') {
                $lifted[] = $phoneBlock->id;
            }
        }
        $phone = $phone ?: $this->str($co['payment_phone'] ?? null);
        if ($qaOff('show_call', 'phone')) {
            $phone = '';
        }

        $waBlock = $firstBlock('whatsapp');
        $whatsapp = $this->str($co['whatsapp_number'] ?? null) ?: $this->str($qa['whatsapp']['number'] ?? null) ?: $this->str($co['whatsapp'] ?? null);
        if ($whatsapp === '' && $waBlock) {
            $whatsapp = $this->str($waBlock->config['phone'] ?? null);
            if ($whatsapp !== '') {
                $lifted[] = $waBlock->id;
            }
        }
        // The old VCard always offered WhatsApp on the main phone number.
        if ($whatsapp === '' && !$isBotanical) {
            $whatsapp = $phone;
        }
        if ($qaOff('show_whatsapp', 'whatsapp')) {
            $whatsapp = '';
        }

        $emailBlock = $firstBlock('email');
        $email = $this->str($qa['email']['address'] ?? null) ?: $this->str($co['email'] ?? null);
        if ($email === '' && $emailBlock) {
            $email = $this->str($emailBlock->config['email'] ?? null);
            if ($email !== '') {
                $lifted[] = $emailBlock->id;
            }
        }
        if ($qaOff('show_email', 'email')) {
            $email = '';
        }

        $socialUrls = is_array($co['social_urls'] ?? null) ? $co['social_urls'] : [];
        $website = $this->webUrl($socialUrls['website'] ?? null) ?: $this->webUrl($co['website_url'] ?? null)
            ?: $this->webUrl($qa['website']['url'] ?? null) ?: $this->webUrl($co['website'] ?? null);
        if ($qaOff('show_website', 'website')) {
            $website = '';
        }

        $mapBlock = $firstBlock('map');
        $address = $this->str($co['address_text'] ?? null);
        if ($address === '' && $mapBlock) {
            $address = $this->str($mapBlock->config['address'] ?? null);
            if ($address !== '') {
                $lifted[] = $mapBlock->id;
            }
        }

        $content['contact'] = [
            'phone' => mb_substr($phone, 0, 24),
            'whatsapp' => mb_substr($whatsapp, 0, 24),
            'email' => mb_substr($email, 0, 254),
            'website' => $website,
            'address' => mb_substr($address, 0, 300),
            'map_url' => $this->webUrl($co['map_url'] ?? null),
        ];

        // ── socials ─────────────────────────────────────────────────────────────────
        $socials = [];
        $toggles = is_array($co['active_action_icons'] ?? null) ? $co['active_action_icons'] : [];
        foreach (self::VCARD_SOCIAL_KEYS as $key) {
            $url = $this->webUrl($socialUrls[$key] ?? null);
            if ($url !== '' && ($toggles[$key] ?? true) !== false) {
                $socials[] = ['platform' => $key === 'twitter' ? 'x' : $key, 'url' => $url];
            }
        }
        if (is_array($co['social_links'] ?? null)) {
            foreach ($co['social_links'] as $link) {
                if (!is_array($link) || ($link['enabled'] ?? true) === false || ($link['is_active'] ?? true) === false) {
                    continue;
                }
                $url = $this->webUrl($link['url'] ?? null);
                if ($url !== '') {
                    $socials[] = ['platform' => $this->detectPlatform($this->str($link['platform'] ?? null), $url), 'url' => $url];
                }
            }
        }
        if ($socials === []) {
            foreach ($visibleBlocks->filter(fn (ProfileBlock $b) => $b->type === 'social') as $block) {
                $url = $this->webUrl($block->config['url'] ?? null);
                if ($url !== '') {
                    $socials[] = ['platform' => $this->detectPlatform($this->str($block->config['platform'] ?? null), $url), 'url' => $url];
                    $lifted[] = $block->id;
                }
            }
        }
        $seen = [];
        $content['socials'] = [];
        foreach ($socials as $social) {
            if (isset($seen[$social['url']]) || count($content['socials']) >= CardContentSchema::LIMITS['socials']) {
                continue;
            }
            $seen[$social['url']] = true;
            $content['socials'][] = ['id' => 's' . (count($content['socials']) + 1)] + $social;
        }

        // ── catalog ─────────────────────────────────────────────────────────────────
        $services = [];
        foreach ((array) ($co['services'] ?? []) as $i => $svc) {
            if (is_array($svc) && ($name = $this->str($svc['name'] ?? $svc['title'] ?? null)) !== '') {
                $services[] = [
                    'id' => $this->itemId($svc['id'] ?? null, 'svc', $i),
                    'name' => mb_substr($name, 0, 100),
                    'description' => mb_substr($this->str($svc['description'] ?? null), 0, 300),
                    'price' => mb_substr($this->str($svc['price'] ?? null), 0, 40),
                    'duration' => mb_substr($this->str($svc['duration'] ?? null), 0, 40),
                    'image_url' => '',
                ];
            }
        }
        $products = [];
        foreach ((array) ($co['products'] ?? []) as $i => $prod) {
            if (is_array($prod) && ($name = $this->str($prod['name'] ?? $prod['title'] ?? null)) !== '') {
                $products[] = [
                    'id' => $this->itemId($prod['id'] ?? null, 'prd', $i),
                    'name' => mb_substr($name, 0, 100),
                    'description' => mb_substr($this->str($prod['description'] ?? null), 0, 300),
                    'price' => mb_substr($this->str($prod['price'] ?? null), 0, 40),
                    'image_url' => $this->imageUrl($prod['image_url'] ?? null),
                    'buy_url' => $this->webUrl($prod['buy_url'] ?? null),
                ];
            }
        }
        $enquiry = $this->str($co['services_enquiry_template'] ?? null) ?: $this->str($co['products_enquiry_template'] ?? null);
        $content['catalog'] = [
            'services_label' => 'Services',
            'products_label' => 'Products',
            'services' => array_slice($services, 0, CardContentSchema::LIMITS['services']),
            'products' => array_slice($products, 0, CardContentSchema::LIMITS['products']),
            'enquiry_message' => mb_substr(str_replace(['{service}', '{product}'], '{item}', $enquiry), 0, 300),
        ];

        // ── payments ────────────────────────────────────────────────────────────────
        $content['payments'] = [
            'upi_id' => $this->str($co['upi_id'] ?? null),
            'upi_name' => mb_substr($this->str($co['bank_holder_name'] ?? null), 0, 100),
            'payment_phone' => mb_substr($this->str($co['payment_phone'] ?? null) ?: $this->str($co['upi_number'] ?? null), 0, 24),
            'bank' => [
                'holder' => mb_substr($this->str($co['bank_holder_name'] ?? null), 0, 100),
                'bank_name' => mb_substr($this->str($co['bank_name'] ?? null), 0, 100),
                'account_number' => $this->str($co['bank_account_number'] ?? null),
                'ifsc' => strtoupper($this->str($co['bank_ifsc'] ?? null)),
            ],
        ];

        // ── gallery ─────────────────────────────────────────────────────────────────
        $images = [];
        foreach ((array) ($co['gallery_images'] ?? []) as $url) {
            if (($clean = $this->imageUrl($url)) !== '') {
                $images[] = $clean;
            }
        }
        $content['gallery'] = [
            'title' => mb_substr($this->str($co['gallery_title'] ?? null), 0, 60),
            'images' => array_slice(array_values(array_unique($images)), 0, CardContentSchema::LIMITS['gallery']),
        ];

        // ── links & documents ───────────────────────────────────────────────────────
        $links = [];
        foreach ((array) ($co['content_links'] ?? []) as $i => $link) {
            if (!is_array($link) || ($link['enabled'] ?? true) === false || ($link['is_active'] ?? true) === false) {
                continue;
            }
            $url = $this->webUrl($link['url'] ?? null);
            if ($url === '') {
                continue;
            }
            $links[] = [
                'id' => $this->itemId($link['id'] ?? null, 'lnk', $i),
                'title' => mb_substr($this->str($link['headline'] ?? null) ?: $this->str($link['title'] ?? null) ?: 'Link', 0, 100),
                'description' => mb_substr($this->str($link['description'] ?? null), 0, 200),
                'url' => $url,
                'icon' => $this->iconKey($link['icon'] ?? null),
                'featured' => (bool) ($link['featured'] ?? $link['is_featured'] ?? false),
            ];
        }
        $drive = is_array($co['drive_block'] ?? null) ? $co['drive_block'] : null;
        if ($drive && ($drive['enabled'] ?? true) !== false && ($url = $this->webUrl($drive['url'] ?? null)) !== '') {
            $links[] = [
                'id' => 'drive',
                'title' => mb_substr($this->str($drive['title'] ?? null) ?: 'Shared files', 0, 100),
                'description' => mb_substr($this->str($drive['description'] ?? null), 0, 200),
                'url' => $url,
                'icon' => 'folder',
                'featured' => false,
            ];
        }
        if (($co['view_products_btn_enabled'] ?? false) === true && ($url = $this->webUrl($co['products_url'] ?? null)) !== '') {
            $links[] = [
                'id' => 'products-link',
                'title' => mb_substr($this->str($co['products_button_text'] ?? null) ?: 'View products', 0, 100),
                'description' => '',
                'url' => $url,
                'icon' => 'shop',
                'featured' => false,
            ];
        }
        $content['links'] = array_slice($links, 0, CardContentSchema::LIMITS['links']);

        $doc = is_array($co['document_block'] ?? null) ? $co['document_block'] : null;
        $content['documents'] = [];
        if ($doc && ($doc['enabled'] ?? true) !== false && ($url = $this->webUrl($doc['file_url'] ?? null)) !== '') {
            $content['documents'][] = [
                'id' => 'doc-1',
                'title' => mb_substr($this->str($doc['title'] ?? null) ?: 'Document', 0, 100),
                'description' => mb_substr($this->str($doc['description'] ?? null), 0, 200),
                'url' => $url,
                'file_name' => mb_substr($this->str($doc['file_name'] ?? null), 0, 200),
                'size_label' => $this->sizeLabel($doc['file_size'] ?? null),
            ];
        }

        // ── actions & layout ────────────────────────────────────────────────────────
        $iconOn = fn (string $key) => ($toggles[$key] ?? true) !== false;
        $booking = '';
        if (($co['booking_enabled'] ?? null) === true || $this->str($co['booking_whatsapp_message'] ?? null) !== '') {
            $booking = $this->str($co['booking_whatsapp_message'] ?? null) ?: 'Hi! I would like to book an appointment.';
        }

        $content['actions'] = [
            'save_contact' => ($co['show_save_contact'] ?? true) !== false && ($co['save_contact_enabled'] ?? true) !== false && $iconOn('save_contact'),
            'share' => ($co['show_share'] ?? true) !== false && ($co['share_profile_enabled'] ?? true) !== false,
            'qr' => ($co['show_qr'] ?? true) !== false && ($co['qr_code_enabled'] ?? true) !== false && $iconOn('qr'),
            'review_url' => $this->webUrl($co['google_review_url'] ?? null),
            'booking_message' => mb_substr($booking, 0, 300),
        ];

        $hidden = [
            'services' => ($co['view_service_btn_enabled'] ?? true) === false,
            'payments' => ($co['pay_now_btn_enabled'] ?? true) === false || ($co['pay_now_enabled'] ?? true) === false,
            'gallery' => ($co['gallery_enabled'] ?? true) === false,
            'review' => ($co['review_btn_enabled'] ?? true) === false,
        ];
        $content['layout'] = array_map(
            fn (array $entry) => ['key' => $entry['key'], 'visible' => !($hidden[$entry['key']] ?? false)],
            CardContentSchema::defaultLayout()
        );

        // ── style ───────────────────────────────────────────────────────────────────
        $style = [];
        if ($isBotanical) {
            $palette = $this->str($co['color_palette'] ?? null) ?: $this->str($co['palette'] ?? null);
            $style['palette'] = self::PALETTE_ALIASES[$palette] ?? ($palette ?: 'sage');
            $style['background'] = preg_replace('/^bg-/', '', $this->str($co['bg_style'] ?? null)) ?: 'botanical';
        } else {
            $style['cover_slideshow'] = ($co['banner_transition'] ?? true) !== false;
        }

        $unmapped = array_values(array_diff(array_keys($co), self::KNOWN_KEYS));
        sort($unmapped);

        return [
            'content' => CardContentSchema::normalize($content),
            'template_style' => $style,
            'lifted_block_ids' => array_values(array_unique($lifted)),
            'unmapped_keys' => $unmapped,
        ];
    }

    public const PALETTE_ALIASES = [
        'executive-forest' => 'sage',
        'enterprise-navy' => 'navy',
        'noir-gold' => 'gold',
        'terracotta-clay' => 'terracotta',
        'charcoal-minimal' => 'charcoal',
    ];

    private function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    private function isHidden(mixed $value): bool
    {
        return in_array($value, [false, 'false', 0, '0', 'off', 'hide', 'hidden'], true);
    }

    /** http(s) URL, with https:// added to bare domains; anything else becomes ''. */
    private function webUrl(mixed $value): string
    {
        $url = $this->str($value);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url) && preg_match('#^[\w\-]+(\.[\w\-]+)+([/?\#:].*)?$#u', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return app(UrlSecurityService::class)->isValidHttpUrl($url) ? mb_substr($url, 0, 2048) : '';
    }

    /** Images may also be site-relative storage paths (/storage/...). */
    private function imageUrl(mixed $value): string
    {
        $url = $this->str($value);
        if ($url !== '' && str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return mb_substr($url, 0, 2048);
        }

        return $this->webUrl($url);
    }

    private function itemId(mixed $id, string $prefix, int|string $index): string
    {
        $candidate = $this->str($id);

        return preg_match(CardContentSchema::ITEM_ID_REGEX, $candidate) === 1 ? $candidate : $prefix . '-' . ((int) $index + 1);
    }

    private function iconKey(mixed $icon): string
    {
        $key = strtolower(preg_replace('/[^a-z_]/i', '', $this->str($icon)) ?? '');

        return mb_substr($key, 0, 30);
    }

    private function sizeLabel(mixed $bytes): string
    {
        if (!is_numeric($bytes) || (int) $bytes <= 0) {
            return '';
        }
        $bytes = (int) $bytes;

        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    private function detectPlatform(string $platform, string $url): string
    {
        $p = strtolower(preg_replace('/[-_ ]/', '', $platform) ?? '');
        $u = strtolower($url);

        $byName = [
            'instagram' => ['insta', 'ig'], 'facebook' => ['facebook', 'fb'], 'linkedin' => ['linkedin'],
            'x' => ['twitter', 'x'], 'youtube' => ['youtube', 'yt'], 'tiktok' => ['tiktok'],
            'whatsapp_channel' => ['whatsappchannel'], 'whatsapp' => ['whatsapp', 'wa'],
            'telegram' => ['telegram', 'tg'], 'github' => ['github'], 'snapchat' => ['snap'],
            'pinterest' => ['pinterest', 'pin'], 'threads' => ['thread'], 'discord' => ['discord'],
            'twitch' => ['twitch'], 'spotify' => ['spotify'], 'behance' => ['behance'],
            'dribbble' => ['dribbble', 'drib'], 'medium' => ['medium'], 'reddit' => ['reddit'],
            'google_business' => ['googlebusiness', 'googlebiz', 'google', 'review'],
        ];
        if ($p !== '' && !in_array($p, ['custom', 'website', 'link', 'url', 'other', 'site', 'web'], true)) {
            foreach ($byName as $id => $needles) {
                foreach ($needles as $needle) {
                    if ($p === $needle || (strlen($needle) > 2 && str_contains($p, $needle))) {
                        return $id;
                    }
                }
            }
        }

        $byHost = [
            'instagram' => ['instagram.com', 'instagr.am'], 'facebook' => ['facebook.com', 'fb.com', 'fb.me'],
            'linkedin' => ['linkedin.com'], 'x' => ['twitter.com', 'x.com'], 'youtube' => ['youtube.com', 'youtu.be'],
            'tiktok' => ['tiktok.com'], 'whatsapp_channel' => ['whatsapp.com/channel'], 'whatsapp' => ['wa.me', 'whatsapp.com'],
            'telegram' => ['t.me', 'telegram.me'], 'github' => ['github.com'], 'snapchat' => ['snapchat.com'],
            'pinterest' => ['pinterest.com', 'pin.it'], 'threads' => ['threads.net'], 'discord' => ['discord.gg', 'discord.com'],
            'twitch' => ['twitch.tv'], 'spotify' => ['spotify.com'], 'behance' => ['behance.net'],
            'dribbble' => ['dribbble.com'], 'medium' => ['medium.com'], 'reddit' => ['reddit.com'],
            'google_business' => ['g.page', 'business.google.com'],
        ];
        foreach ($byHost as $id => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($u, '//' . $needle) || str_contains($u, '.' . $needle)) {
                    return $id;
                }
            }
        }

        return in_array($p, ['website', 'web', 'site', 'link', 'url'], true) ? 'website' : 'other';
    }
}
