<?php

namespace Tests\Unit\Services;

use App\Services\ThemeTokenSanitizer;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ThemeTokenSanitizerTest extends TestCase
{
    private ThemeTokenSanitizer $sanitizer;

    private const CORE = [
        'color_background' => '#f0f4f8',
        'color_surface' => '#ffffff',
        'color_text_primary' => '#1e293b',
        'color_text_secondary' => '#64748b',
        'color_accent' => '#f97316',
        'font_family' => 'poppins',
        'button_radius' => 'large',
        'button_style' => 'solid',
        'animation' => 'none',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new ThemeTokenSanitizer();
    }

    public function test_dangerous_url_schemes_are_removed(): void
    {
        foreach ([
            'javascript:alert(1)',
            'JavaScript:alert(1)',
            " java\tscript:alert(1)",
            'data:text/html;base64,PHNjcmlwdD4=',
            'vbscript:msgbox(1)',
            'file:///etc/passwd',
        ] as $bad) {
            $this->assertSame('', $this->sanitizer->sanitizeUrl($bad), $bad);
        }
    }

    public function test_safe_urls_are_kept_unchanged(): void
    {
        foreach ([
            'https://instagram.com/aisha',
            'http://example.com',
            'mailto:hello@example.com',
            'tel:+919000000000',
            'studionoor.in',
            'example.com:8080/path',
            '#services',
            '/storage/media/a.jpg',
        ] as $good) {
            $this->assertSame($good, $this->sanitizer->sanitizeUrl($good), $good);
        }
    }

    public function test_custom_options_urls_are_cleaned_at_any_depth(): void
    {
        $clean = $this->sanitizer->sanitize(self::CORE + ['custom_options' => [
            'map_url' => 'javascript:alert(1)',
            'website_url' => 'https://ok.example',
            'social_urls' => ['instagram' => 'javascript:steal()', 'linkedin' => 'https://linkedin.com/in/x'],
            'banner_images' => ['https://cdn.example/a.jpg', 'data:image/png;base64,AAA'],
            'products' => [['name' => 'Chair', 'buy_url' => 'javascript:buy()', 'image_url' => 'https://cdn.example/c.jpg']],
            'content_links' => [['title' => 'Book', 'url' => 'vbscript:x']],
        ]]);

        $opts = $clean['custom_options'];
        $this->assertSame('', $opts['map_url']);
        $this->assertSame('https://ok.example', $opts['website_url']);
        $this->assertSame('', $opts['social_urls']['instagram']);
        $this->assertSame('https://linkedin.com/in/x', $opts['social_urls']['linkedin']);
        $this->assertSame(['https://cdn.example/a.jpg', ''], $opts['banner_images']);
        $this->assertSame('', $opts['products'][0]['buy_url']);
        $this->assertSame('https://cdn.example/c.jpg', $opts['products'][0]['image_url']);
        $this->assertSame('', $opts['content_links'][0]['url']);
    }

    public function test_non_hex_colours_are_dropped_and_other_values_kept(): void
    {
        $clean = $this->sanitizer->sanitize(self::CORE + ['custom_options' => [
            'primary_button_color' => 'red; background:url(https://evil.example/t.png)',
            'pay_button_color' => '#16a34a',
            'custom_colors' => ['primary' => 'expression(alert(1))', 'accent' => '#abc'],
            'color_palette' => 'sage',
            'upi_id' => 'shop@okaxis',
            'show_meta_verified' => false,
            'gallery_images' => [],
        ]]);

        $opts = $clean['custom_options'];
        $this->assertArrayNotHasKey('primary_button_color', $opts);
        $this->assertSame('#16a34a', $opts['pay_button_color']);
        $this->assertSame(['accent' => '#abc'], $opts['custom_colors']);
        $this->assertSame('sage', $opts['color_palette']);
        $this->assertSame('shop@okaxis', $opts['upi_id']);
        $this->assertFalse($opts['show_meta_verified']);
        $this->assertSame([], $opts['gallery_images']);
    }

    public function test_invalid_core_tokens_fall_back_to_defaults_and_unknown_keys_are_removed(): void
    {
        $clean = $this->sanitizer->sanitize(
            ['color_accent' => 'url(x)', 'font_family' => 'comic-sans', 'injected' => '<script>', 'hide_branding' => 1],
            self::CORE
        );

        $this->assertSame('#f97316', $clean['color_accent']);
        $this->assertSame('poppins', $clean['font_family']);
        $this->assertArrayNotHasKey('injected', $clean);
        $this->assertTrue($clean['hide_branding']);
    }

    public function test_oversized_custom_options_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->sanitizer->sanitize(self::CORE + ['custom_options' => [
            'notes' => array_fill(0, 80, str_repeat('x', 4000)),
        ]]);
    }

    public function test_is_web_url_accepts_only_http_and_site_relative_paths(): void
    {
        $this->assertTrue($this->sanitizer->isWebUrl('https://cdn.example/a.jpg'));
        $this->assertTrue($this->sanitizer->isWebUrl('/storage/a.jpg'));
        $this->assertFalse($this->sanitizer->isWebUrl('//evil.example/a.jpg'));
        $this->assertFalse($this->sanitizer->isWebUrl('javascript:alert(1)'));
        $this->assertFalse($this->sanitizer->isWebUrl('mailto:a@b.c'));
        $this->assertFalse($this->sanitizer->isWebUrl(''));
        $this->assertFalse($this->sanitizer->isWebUrl(null));
    }
}
