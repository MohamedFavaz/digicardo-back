<?php

namespace Tests\Feature\Console;

use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrateProfileContentTest extends TestCase
{
    use RefreshDatabase;

    private function legacyProfile(string $username, array $customOptions): Profile
    {
        $user = User::factory()->create();

        return Profile::create([
            'user_id' => $user->id,
            'username' => $username,
            'display_name' => ucfirst($username),
            'template_id' => 'vcard',
            'theme_tokens' => ['color_accent' => '#0F766E', 'custom_options' => $customOptions],
            'is_public' => true,
            'version' => 1,
        ]);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $profile = $this->legacyProfile('noor', ['whatsapp_number' => '+91 98450 12345']);

        $this->artisan('profiles:migrate-content', ['--dry-run' => true])
            ->expectsOutputToContain('Would convert 1 profile(s).')
            ->assertSuccessful();

        $this->assertNull($profile->fresh()->content);
    }

    public function test_real_run_converts_backs_up_and_is_idempotent(): void
    {
        $profile = $this->legacyProfile('noor', ['upi_id' => 'noor@okhdfc', 'banner_transition' => false]);
        $phone = ProfileBlock::create([
            'profile_id' => $profile->id, 'type' => 'phone', 'sort_order' => 1,
            'config' => ['label' => 'Call', 'phone' => '+91 98450 12345'], 'is_visible' => true, 'version' => 1,
        ]);

        $this->artisan('profiles:migrate-content')->expectsOutputToContain('Converted 1 profile(s).')->assertSuccessful();

        $fresh = $profile->fresh();
        $this->assertSame('noor@okhdfc', $fresh->content['payments']['upi_id']);
        $this->assertSame('+91 98450 12345', $fresh->content['contact']['phone']);
        $this->assertSame('noor@okhdfc', $fresh->theme_tokens_legacy['custom_options']['upi_id']);
        $this->assertFalse($fresh->theme_tokens['template_style']['cover_slideshow']);
        $this->assertSame(2, $fresh->version);
        $this->assertFalse((bool) $phone->fresh()->is_visible);

        $this->artisan('profiles:migrate-content')->expectsOutputToContain('Converted 0 profile(s).')->assertSuccessful();
        $this->assertSame(2, $profile->fresh()->version);
    }

    public function test_botanical_palette_becomes_theme_colours_so_the_card_looks_the_same(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'rohan',
            'display_name' => 'Rohan',
            'template_id' => 'botanical',
            'theme_tokens' => [
                'color_background' => '#F4EFEB',
                'color_accent' => '#2D4A3E',
                'custom_options' => [
                    'color_palette' => 'enterprise-navy',
                    'bg_style' => 'bg-silk',
                    'custom_colors' => ['accent' => '#123456', 'primary' => 'javascript:1'],
                ],
            ],
            'is_public' => true,
            'version' => 1,
        ]);

        $this->artisan('profiles:migrate-content')->assertSuccessful();

        $tokens = $profile->fresh()->theme_tokens;
        $this->assertSame('navy', $tokens['preset']);
        $this->assertSame('#F1F4F9', $tokens['color_background']);
        $this->assertSame('#0A192F', $tokens['color_text_primary']);
        $this->assertSame('#123456', $tokens['color_accent'], 'a valid custom colour still wins');
        $this->assertSame(['background' => 'silk'], $tokens['template_style']);
    }

    public function test_profiles_with_unknown_keys_are_skipped_unless_allowed(): void
    {
        $profile = $this->legacyProfile('odd', ['mystery_widget' => 1]);

        $this->artisan('profiles:migrate-content')->assertFailed();
        $this->assertNull($profile->fresh()->content);

        $this->artisan('profiles:migrate-content', ['--allow-unmapped' => true, '--profile' => 'odd'])->assertSuccessful();
        $this->assertIsArray($profile->fresh()->content);
    }
}
