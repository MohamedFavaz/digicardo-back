<?php

namespace Tests\Unit\Services;

use App\Services\TemplateManifestService;
use App\Services\TemplateService;
use App\Services\ThemeTokenSanitizer;
use Tests\TestCase;

/**
 * Template manifests (ADR-031) must agree with the validation constants, and only
 * manifest-declared template_style values may be stored.
 */
class TemplateManifestTest extends TestCase
{
    public function test_manifests_match_the_approved_template_list(): void
    {
        $manifests = app(TemplateManifestService::class)->all();

        $this->assertSame(['vcard', 'botanical', 'motion', 'poster', 'showcase'], array_keys($manifests));
        foreach (TemplateService::APPROVED_TEMPLATES as $id => $template) {
            $this->assertSame($template['name'], $manifests[$id]['name']);
            $this->assertSame($template['default_theme'], $manifests[$id]['default_theme']);
            $this->assertNotEmpty($manifests[$id]['presets']);
        }
    }

    public function test_template_style_is_checked_against_controls(): void
    {
        $service = app(TemplateManifestService::class);

        $this->assertSame(
            ['background' => 'topo'],
            $service->sanitizeStyle('botanical', ['background' => 'topo', 'injected' => 'x'])
        );
        $this->assertSame([], $service->sanitizeStyle('botanical', ['background' => 'url(javascript:1)']));
        $this->assertSame(['cover_slideshow' => false], $service->sanitizeStyle('vcard', ['cover_slideshow' => false]));
        $this->assertSame([], $service->sanitizeStyle('vcard', ['cover_slideshow' => 'yes']));
    }

    public function test_sanitizer_keeps_valid_style_and_preset_only_for_the_given_template(): void
    {
        $sanitizer = new ThemeTokenSanitizer();

        $clean = $sanitizer->sanitize([
            'color_accent' => '#2D6A4F',
            'preset' => 'navy',
            'template_style' => ['background' => 'silk', 'evil' => '<script>'],
        ], [], 'botanical');

        $this->assertSame('navy', $clean['preset']);
        $this->assertSame(['background' => 'silk'], $clean['template_style']);

        $vcard = $sanitizer->sanitize(['preset' => 'navy', 'template_style' => ['background' => 'silk']], [], 'vcard');
        $this->assertArrayNotHasKey('preset', $vcard);
        $this->assertSame([], $vcard['template_style']);
    }

    public function test_templates_endpoint_returns_full_manifests(): void
    {
        $this->getJson('/api/v1/templates')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'vcard')
            ->assertJsonPath('data.0.name', 'Classic')
            ->assertJsonPath('data.1.controls.0.key', 'background');
    }
}
