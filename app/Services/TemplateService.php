<?php

namespace App\Services;

class TemplateService
{
    public const APPROVED_TEMPLATES = [
        'vcard' => [
            'id' => 'vcard',
            'name' => 'VCard Business',
            'description' => 'Digital business card with animated banner slideshow, meta verified badge, service catalogue, UPI Pay Now, and action icon grid.',
            'category' => 'Business',
            'default_theme' => [
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
        return array_values(self::APPROVED_TEMPLATES);
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
