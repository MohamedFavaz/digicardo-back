<?php

namespace App\Services;

class TemplateService
{
    /**
     * Template ids and default themes used for validation. The full manifests (sections,
     * style controls, presets) live in resources/templates/*.json and must agree with this
     * list — TemplateManifestTest checks it.
     */
    public const APPROVED_TEMPLATES = [
        'vcard' => [
            'id' => 'vcard',
            'name' => 'Classic',
            'description' => 'A clean business card: cover photo, big contact buttons, services, shop, payments and photos.',
            'category' => 'Business',
            'default_theme' => [
                'color_background' => '#FAFAF8',
                'color_surface' => '#FFFFFF',
                'color_text_primary' => '#18181B',
                'color_text_secondary' => '#52525B',
                'color_accent' => '#0F766E',
                'font_family' => 'inter',
                'button_radius' => 'large',
                'button_style' => 'solid',
                'animation' => 'none',
            ],
        ],
        'botanical' => [
            'id' => 'botanical',
            'name' => 'Botanical',
            'description' => 'A calm executive card with soft line art, a serif name, link cards and five colour palettes.',
            'category' => 'Corporate',
            'default_theme' => [
                'color_background' => '#F2F5F1',
                'color_surface' => '#FFFFFF',
                'color_text_primary' => '#1B4332',
                'color_text_secondary' => '#2D6A4F',
                'color_accent' => '#2D6A4F',
                'font_family' => 'inter',
                'button_radius' => 'large',
                'button_style' => 'solid',
                'animation' => 'fade',
            ],
        ],
        'showcase' => [
            'id' => 'showcase',
            'name' => 'Showcase',
            'description' => 'A business showcase: big action buttons, round contact icons, opening hours, photo gallery and a three-column layout on desktop.',
            'category' => 'Business',
            'default_theme' => [
                'color_background' => '#F3F4FA',
                'color_surface' => '#FFFFFF',
                'color_text_primary' => '#1A1F36',
                'color_text_secondary' => '#5B6178',
                'color_accent' => '#2F3A9E',
                'font_family' => 'poppins',
                'button_radius' => 'medium',
                'button_style' => 'solid',
                'animation' => 'fade',
            ],
        ],
        'poster' => [
            'id' => 'poster',
            'name' => 'Poster',
            'description' => 'A bold shop poster for any shop: your product photo or a ready-made picture, your name in giant letters and glossy tap icons for WhatsApp, delivery apps and socials.',
            'category' => 'Business',
            'default_theme' => [
                'color_background' => '#F7F08A',
                'color_surface' => '#FFFFFF',
                'color_text_primary' => '#3A0718',
                'color_text_secondary' => '#6B1F35',
                'color_accent' => '#D90D52',
                'font_family' => 'poppins',
                'button_radius' => 'large',
                'button_style' => 'solid',
                'animation' => 'fade',
            ],
        ],
        'motion' => [
            'id' => 'motion',
            'name' => 'Motion',
            'description' => 'A modern animated card: a glowing intro, a 3D card that flips to its QR code, sections that glide in as you scroll, tap effects and a quick-contact button that fans out.',
            'category' => 'Modern',
            'default_theme' => [
                'color_background' => '#0A0A14',
                'color_surface' => '#151522',
                'color_text_primary' => '#F5F5FA',
                'color_text_secondary' => '#A9A9C2',
                'color_accent' => '#8B5CF6',
                'font_family' => 'outfit',
                'button_radius' => 'large',
                'button_style' => 'solid',
                'animation' => 'fade',
            ],
        ],
    ];

    public const FONT_ALLOWLIST = [
        'inter',
        'roboto',
        'outfit',
        'poppins',
        'lato',
        'montserrat',
        'raleway',
        'nunito',
        'playfair-display',
        'system-ui',
    ];

    public const BUTTON_RADIUS = ['none', 'small', 'medium', 'large', 'pill'];
    public const BUTTON_STYLES = ['solid', 'outline', 'ghost', 'soft', 'glass'];
    public const ANIMATIONS = ['none', 'fade', 'slide', 'scale'];
    public const HEX_COLOR_REGEX = '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/';

    /**
     * Retrieve list of all approved templates with metadata.
     *
     * @return array<string, mixed>
     */
    public function getAllTemplates(): array
    {
        // Full manifests: id, name, description, category, sections, default_theme, controls, presets.
        return array_values(app(TemplateManifestService::class)->all());
    }

    /**
     * Static check for code that has no service instance (e.g. agent card creation).
     */
    public static function isApprovedId(string $templateId): bool
    {
        return isset(self::APPROVED_TEMPLATES[$templateId]);
    }

    /**
     * Check if a template ID is valid.
     */
    public function isValidTemplate(string $templateId): bool
    {
        return isset(self::APPROVED_TEMPLATES[$templateId]);
    }

    /**
     * Get specific template definition or fallback to vcard.
     *
     * @param string $templateId
     * @return array<string, mixed>
     */
    public function getTemplate(string $templateId): array
    {
        return self::APPROVED_TEMPLATES[$templateId] ?? self::APPROVED_TEMPLATES['vcard'];
    }
}
