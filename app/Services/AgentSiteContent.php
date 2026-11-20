<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Typed content of an agent's landing page.
 *
 * `normalize()` is the single gate for anything stored or served: unknown keys are dropped,
 * text is trimmed and capped, lists are limited, images must be the agent's own uploads and
 * links must be http(s). Missing parts fall back to sensible defaults.
 */
class AgentSiteContent
{
    public const PRESETS = ['sage', 'midnight', 'sunrise', 'ocean'];

    /** Sections the agent can reorder / switch off (hero is always first, footer always last). */
    public const SECTIONS = ['about', 'packages', 'showcase', 'steps', 'testimonials', 'faq', 'contact'];

    public const LIMITS = [
        'stats' => 4,
        'about_points' => 6,
        'packages' => 4,
        'package_features' => 8,
        'showcase' => 8,
        'steps' => 4,
        'testimonials' => 6,
        'faq' => 10,
    ];

    /** @return array<string, mixed> */
    public static function defaults(?User $agent = null): array
    {
        $brand = trim((string) ($agent?->name ?? '')) ?: 'Your brand';
        $phone = (string) ($agent?->mobile ?? '');

        return [
            'brand' => ['name' => $brand, 'tagline' => 'Digital business cards', 'logo_url' => ''],
            'theme' => ['preset' => 'sage', 'accent' => ''],
            'hero' => [
                'eyebrow' => 'Smart digital cards',
                'title' => 'Your business, one tap',
                'highlight' => 'away.',
                'subtitle' => 'We design beautiful digital business cards and NFC cards for shops, professionals and teams — ready to share on WhatsApp in a day.',
                'image_url' => '',
                'cta_label' => 'Get your card',
                'secondary_label' => 'See examples',
            ],
            'stats' => [
                ['id' => 's1', 'value' => '500+', 'label' => 'Cards created'],
                ['id' => 's2', 'value' => '24 h', 'label' => 'Delivery'],
                ['id' => 's3', 'value' => '4.9★', 'label' => 'Customer rating'],
            ],
            'about' => [
                'enabled' => true,
                'title' => 'Cards that work as hard as you do',
                'text' => 'Share your contact, WhatsApp, location, payments and products in one link. Update it any time — no reprinting.',
                'image_url' => '',
                'points' => ['Ready in 24 hours', 'Works on every phone', 'Free updates', 'NFC tap cards available'],
            ],
            'packages' => [
                'enabled' => true,
                'title' => 'Simple, honest pricing',
                'subtitle' => 'One-time setup. Renew yearly to keep your card live.',
                'items' => [
                    ['id' => 'p1', 'name' => 'Starter', 'price' => '₹499', 'period' => '/ year', 'description' => 'Everything to get started.', 'features' => ['Digital card', 'WhatsApp & call buttons', 'QR code'], 'featured' => false],
                    ['id' => 'p2', 'name' => 'Business', 'price' => '₹999', 'period' => '/ year', 'description' => 'Most popular for shops.', 'features' => ['Everything in Starter', 'Photo gallery & products', 'Payments (UPI)', 'NFC tap card'], 'featured' => true],
                    ['id' => 'p3', 'name' => 'Premium', 'price' => '₹1,999', 'period' => '/ year', 'description' => 'For brands that want more.', 'features' => ['Everything in Business', 'Custom design', 'Priority support'], 'featured' => false],
                ],
            ],
            'showcase' => ['enabled' => true, 'title' => 'Cards we have made', 'subtitle' => 'Tap any card to open it live.', 'profile_ids' => []],
            'steps' => [
                'enabled' => true,
                'title' => 'How it works',
                'items' => [
                    ['id' => 't1', 'title' => 'Tell us about you', 'text' => 'Send your details and logo on WhatsApp.'],
                    ['id' => 't2', 'title' => 'We design your card', 'text' => 'We build and check everything for you.'],
                    ['id' => 't3', 'title' => 'Share it everywhere', 'text' => 'Send the link, print the QR or tap the NFC card.'],
                ],
            ],
            'testimonials' => [
                'enabled' => true,
                'title' => 'Loved by local businesses',
                'items' => [
                    ['id' => 'r1', 'name' => 'Happy customer', 'role' => 'Shop owner', 'quote' => 'Customers now find my shop and WhatsApp me straight from the card. Best investment this year.'],
                ],
            ],
            'faq' => [
                'enabled' => true,
                'title' => 'Questions, answered',
                'items' => [
                    ['id' => 'q1', 'q' => 'Can I change my card later?', 'a' => 'Yes. Updates are free — your link and QR code never change.'],
                    ['id' => 'q2', 'q' => 'Does it work without an app?', 'a' => 'Yes. Your card opens in any phone browser.'],
                ],
            ],
            'contact' => [
                'enabled' => true,
                'title' => 'Let’s make your card',
                'text' => 'Tell us a little about your business and we’ll get back to you today.',
                'phone' => $phone,
                'whatsapp' => $phone,
                'email' => (string) ($agent?->email ?? ''),
                'address' => '',
                'map_url' => '',
                'instagram' => '',
                'facebook' => '',
                'website' => '',
            ],
            'lead_form' => ['enabled' => true, 'title' => 'Request a call back', 'button_label' => 'Send request'],
            'layout' => self::SECTIONS,
            'seo' => ['title' => '', 'description' => ''],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @param  callable(string): bool  $imageAllowed  whether an image URL may be used
     * @return array<string, mixed>
     */
    public static function normalize(?array $input, ?User $agent, callable $imageAllowed): array
    {
        $d = self::defaults($agent);
        $in = is_array($input) ? $input : [];

        $text = fn ($v, int $max): string => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max);
        $bool = fn ($v, bool $fallback): bool => is_bool($v) ? $v : ($v === null ? $fallback : (bool) $v);
        $image = fn ($v): string => ($url = $text($v, 2048)) !== '' && $imageAllowed($url) ? $url : '';
        $link = fn ($v): string => ($url = $text($v, 500)) !== '' && preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $url) ? $url : '';
        $id = fn ($v): string => preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) $v) ? (string) $v : Str::lower(Str::random(8));
        $part = fn (string $k): array => is_array($in[$k] ?? null) ? $in[$k] : [];
        $list = fn ($v, int $max): array => array_slice(array_values(array_filter(is_array($v) ? $v : [], 'is_array')), 0, $max);

        $brand = $part('brand');
        $theme = $part('theme');
        $hero = $part('hero');
        $about = $part('about');
        $packages = $part('packages');
        $showcase = $part('showcase');
        $steps = $part('steps');
        $testimonials = $part('testimonials');
        $faq = $part('faq');
        $contact = $part('contact');
        $lead = $part('lead_form');
        $seo = $part('seo');

        $preset = in_array($theme['preset'] ?? null, self::PRESETS, true) ? $theme['preset'] : $d['theme']['preset'];
        $accent = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($theme['accent'] ?? '')) ? strtoupper($theme['accent']) : '';

        $layout = array_values(array_unique(array_filter(
            is_array($in['layout'] ?? null) ? $in['layout'] : $d['layout'],
            fn ($k) => is_string($k) && in_array($k, self::SECTIONS, true)
        )));
        foreach (self::SECTIONS as $k) {
            if (! in_array($k, $layout, true)) {
                $layout[] = $k;
            }
        }

        return [
            'brand' => [
                'name' => $text($brand['name'] ?? $d['brand']['name'], 60) ?: $d['brand']['name'],
                'tagline' => $text($brand['tagline'] ?? $d['brand']['tagline'], 80),
                'logo_url' => $image($brand['logo_url'] ?? ''),
            ],
            'theme' => ['preset' => $preset, 'accent' => $accent],
            'hero' => [
                'eyebrow' => $text($hero['eyebrow'] ?? $d['hero']['eyebrow'], 40),
                'title' => $text($hero['title'] ?? $d['hero']['title'], 80) ?: $d['hero']['title'],
                'highlight' => $text($hero['highlight'] ?? $d['hero']['highlight'], 40),
                'subtitle' => $text($hero['subtitle'] ?? $d['hero']['subtitle'], 240),
                'image_url' => $image($hero['image_url'] ?? ''),
                'cta_label' => $text($hero['cta_label'] ?? $d['hero']['cta_label'], 30) ?: $d['hero']['cta_label'],
                'secondary_label' => $text($hero['secondary_label'] ?? $d['hero']['secondary_label'], 30),
            ],
            'stats' => array_map(fn ($s) => [
                'id' => $id($s['id'] ?? ''),
                'value' => $text($s['value'] ?? '', 12),
                'label' => $text($s['label'] ?? '', 30),
            ], $list(array_key_exists('stats', $in) ? $in['stats'] : $d['stats'], self::LIMITS['stats'])),
            'about' => [
                'enabled' => $bool($about['enabled'] ?? null, true),
                'title' => $text($about['title'] ?? $d['about']['title'], 80),
                'text' => $text($about['text'] ?? $d['about']['text'], 800),
                'image_url' => $image($about['image_url'] ?? ''),
                'points' => array_slice(array_values(array_filter(array_map(
                    fn ($p) => $text($p, 80),
                    is_array($about['points'] ?? null) ? $about['points'] : $d['about']['points']
                ))), 0, self::LIMITS['about_points']),
            ],
            'packages' => [
                'enabled' => $bool($packages['enabled'] ?? null, true),
                'title' => $text($packages['title'] ?? $d['packages']['title'], 80),
                'subtitle' => $text($packages['subtitle'] ?? $d['packages']['subtitle'], 160),
                'items' => array_map(fn ($p) => [
                    'id' => $id($p['id'] ?? ''),
                    'name' => $text($p['name'] ?? '', 40),
                    'price' => $text($p['price'] ?? '', 20),
                    'period' => $text($p['period'] ?? '', 20),
                    'description' => $text($p['description'] ?? '', 120),
                    'features' => array_slice(array_values(array_filter(array_map(fn ($f) => $text($f, 60), is_array($p['features'] ?? null) ? $p['features'] : []))), 0, self::LIMITS['package_features']),
                    'featured' => (bool) ($p['featured'] ?? false),
                ], $list(array_key_exists('items', $packages) ? $packages['items'] : $d['packages']['items'], self::LIMITS['packages'])),
            ],
            'showcase' => [
                'enabled' => $bool($showcase['enabled'] ?? null, true),
                'title' => $text($showcase['title'] ?? $d['showcase']['title'], 80),
                'subtitle' => $text($showcase['subtitle'] ?? $d['showcase']['subtitle'], 160),
                'profile_ids' => array_slice(array_values(array_unique(array_filter(
                    is_array($showcase['profile_ids'] ?? null) ? $showcase['profile_ids'] : [],
                    fn ($v) => is_string($v) && preg_match('/^[0-9A-Za-z]{26}$/', $v)
                ))), 0, self::LIMITS['showcase']),
            ],
            'steps' => [
                'enabled' => $bool($steps['enabled'] ?? null, true),
                'title' => $text($steps['title'] ?? $d['steps']['title'], 80),
                'items' => array_map(fn ($s) => [
                    'id' => $id($s['id'] ?? ''),
                    'title' => $text($s['title'] ?? '', 50),
                    'text' => $text($s['text'] ?? '', 160),
                ], $list(array_key_exists('items', $steps) ? $steps['items'] : $d['steps']['items'], self::LIMITS['steps'])),
            ],
            'testimonials' => [
                'enabled' => $bool($testimonials['enabled'] ?? null, true),
                'title' => $text($testimonials['title'] ?? $d['testimonials']['title'], 80),
                'items' => array_map(fn ($t) => [
                    'id' => $id($t['id'] ?? ''),
                    'name' => $text($t['name'] ?? '', 50),
                    'role' => $text($t['role'] ?? '', 60),
                    'quote' => $text($t['quote'] ?? '', 300),
                ], $list(array_key_exists('items', $testimonials) ? $testimonials['items'] : $d['testimonials']['items'], self::LIMITS['testimonials'])),
            ],
            'faq' => [
                'enabled' => $bool($faq['enabled'] ?? null, true),
                'title' => $text($faq['title'] ?? $d['faq']['title'], 80),
                'items' => array_map(fn ($q) => [
                    'id' => $id($q['id'] ?? ''),
                    'q' => $text($q['q'] ?? '', 120),
                    'a' => $text($q['a'] ?? '', 500),
                ], $list(array_key_exists('items', $faq) ? $faq['items'] : $d['faq']['items'], self::LIMITS['faq'])),
            ],
            'contact' => [
                'enabled' => $bool($contact['enabled'] ?? null, true),
                'title' => $text($contact['title'] ?? $d['contact']['title'], 80),
                'text' => $text($contact['text'] ?? $d['contact']['text'], 200),
                'phone' => $text($contact['phone'] ?? $d['contact']['phone'], 24),
                'whatsapp' => $text($contact['whatsapp'] ?? $d['contact']['whatsapp'], 24),
                'email' => filter_var($text($contact['email'] ?? $d['contact']['email'], 120), FILTER_VALIDATE_EMAIL) ?: '',
                'address' => $text($contact['address'] ?? '', 200),
                'map_url' => $link($contact['map_url'] ?? ''),
                'instagram' => $link($contact['instagram'] ?? ''),
                'facebook' => $link($contact['facebook'] ?? ''),
                'website' => $link($contact['website'] ?? ''),
            ],
            'lead_form' => [
                'enabled' => $bool($lead['enabled'] ?? null, true),
                'title' => $text($lead['title'] ?? $d['lead_form']['title'], 60),
                'button_label' => $text($lead['button_label'] ?? $d['lead_form']['button_label'], 30) ?: $d['lead_form']['button_label'],
            ],
            'layout' => $layout,
            'seo' => [
                'title' => $text($seo['title'] ?? '', 70),
                'description' => $text($seo['description'] ?? '', 160),
            ],
        ];
    }
}
