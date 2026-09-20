<?php

namespace Tests\Feature\Api\V1;

use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateAndThemeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->user = User::factory()->create();

        $proPlan = \App\Models\Plan::where('code', 'pro')->first();
        if ($proPlan) {
            \App\Models\Subscription::create([
                'user_id' => $this->user->id,
                'plan_id' => $proPlan->id,
                'status' => \App\Enums\SubscriptionStatus::Active,
                'billing_interval' => 'monthly',
            ]);
        }

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'username' => 'themeguy',
            'display_name' => 'Theme Guy',
            'bio' => 'Original Bio',
            'template_id' => 'vcard',
            'version' => 1,
        ]);
    }

    public function test_public_can_retrieve_available_templates(): void
    {
        $response = $this->getJson(route('api.v1.templates.index'));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $templates = $response->json('data');
        $this->assertIsArray($templates);
        $this->assertCount(1, $templates);

        $ids = array_column($templates, 'id');
        $this->assertContains('vcard', $ids);
    }

    public function test_invalid_template_id_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson(route('api.v1.profile.appearance.update'), [
                'template_id' => 'unsupported-custom-theme',
                'version' => (int) $this->profile->version,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                ],
            ]);
    }

    public function test_authenticated_user_can_update_appearance_template_and_theme(): void
    {
        $themePayload = [
            'color_background' => '#0f172a',
            'color_surface' => '#1e293b',
            'color_text_primary' => '#ffffff',
            'color_text_secondary' => '#94a3b8',
            'color_accent' => '#38bdf8',
            'font_family' => 'outfit',
            'button_radius' => 'pill',
            'button_style' => 'glass',
            'animation' => 'fade',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson(route('api.v1.profile.appearance.update'), [
                'template_id' => 'vcard',
                'version' => (int) $this->profile->version,
                'theme_tokens' => $themePayload,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'template_id' => 'vcard',
                    'version' => 2,
                    'theme_tokens' => $themePayload,
                ],
            ]);

        $this->assertEquals('vcard', $this->profile->fresh()->template_id);
        $this->assertEquals($themePayload, $this->profile->fresh()->theme_tokens);
    }

    public function test_unauthenticated_user_cannot_update_appearance(): void
    {
        $response = $this->putJson(route('api.v1.profile.appearance.update'), [
            'template_id' => 'vcard',
            'version' => 1,
        ]);

        $response->assertStatus(401);
    }

    public function test_invalid_hex_color_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson(route('api.v1.profile.appearance.update'), [
                'template_id' => 'vcard',
                'version' => (int) $this->profile->version,
                'theme_tokens' => [
                    'color_background' => 'red', // not hex format
                    'color_surface' => '#ffffff',
                    'color_text_primary' => '#000000',
                    'color_text_secondary' => '#666666',
                    'color_accent' => '#3b82f6',
                    'font_family' => 'inter',
                    'button_radius' => 'medium',
                    'button_style' => 'solid',
                    'animation' => 'none',
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_css_injection_payload_rejected(): void
    {
        $maliciousColors = [
            '#000; background: url(http://evil.com/xss);',
            '#fff; } body { display: none }',
            'expression(alert(1))',
            'rgba(0,0,0,1)',
        ];

        foreach ($maliciousColors as $badColor) {
            $response = $this->actingAs($this->user, 'sanctum')
                ->putJson(route('api.v1.profile.appearance.update'), [
                    'template_id' => 'vcard',
                    'version' => (int) $this->profile->version,
                    'theme_tokens' => [
                        'color_background' => $badColor,
                        'color_surface' => '#ffffff',
                        'color_text_primary' => '#000000',
                        'color_text_secondary' => '#666666',
                        'color_accent' => '#3b82f6',
                        'font_family' => 'inter',
                        'button_radius' => 'medium',
                        'button_style' => 'solid',
                        'animation' => 'none',
                    ],
                ]);

            $response->assertStatus(422);
        }
    }

    public function test_invalid_font_family_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson(route('api.v1.profile.appearance.update'), [
                'template_id' => 'vcard',
                'version' => (int) $this->profile->version,
                'theme_tokens' => [
                    'color_background' => '#ffffff',
                    'color_surface' => '#f8fafc',
                    'color_text_primary' => '#000000',
                    'color_text_secondary' => '#666666',
                    'color_accent' => '#3b82f6',
                    'font_family' => 'Comic Sans MS', // not in allowlist
                    'button_radius' => 'medium',
                    'button_style' => 'solid',
                    'animation' => 'none',
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_invalid_button_radius_enum_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson(route('api.v1.profile.appearance.update'), [
                'template_id' => 'vcard',
                'version' => (int) $this->profile->version,
                'theme_tokens' => [
                    'color_background' => '#ffffff',
                    'color_surface' => '#f8fafc',
                    'color_text_primary' => '#000000',
                    'color_text_secondary' => '#666666',
                    'color_accent' => '#3b82f6',
                    'font_family' => 'inter',
                    'button_radius' => 'extra-super-round', // invalid enum
                    'button_style' => 'solid',
                    'animation' => 'none',
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_invalid_button_style_enum_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson(route('api.v1.profile.appearance.update'), [
                'template_id' => 'vcard',
                'version' => (int) $this->profile->version,
                'theme_tokens' => [
                    'color_background' => '#ffffff',
                    'color_surface' => '#f8fafc',
                    'color_text_primary' => '#000000',
                    'color_text_secondary' => '#666666',
                    'color_accent' => '#3b82f6',
                    'font_family' => 'inter',
                    'button_radius' => 'medium',
                    'button_style' => 'hyper-3d', // invalid enum
                    'animation' => 'none',
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_invalid_animation_enum_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson(route('api.v1.profile.appearance.update'), [
                'template_id' => 'vcard',
                'version' => (int) $this->profile->version,
                'theme_tokens' => [
                    'color_background' => '#ffffff',
                    'color_surface' => '#f8fafc',
                    'color_text_primary' => '#000000',
                    'color_text_secondary' => '#666666',
                    'color_accent' => '#3b82f6',
                    'font_family' => 'inter',
                    'button_radius' => 'medium',
                    'button_style' => 'solid',
                    'animation' => 'spin-wildly', // invalid enum
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_stale_appearance_update_returns_409_conflict(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson(route('api.v1.profile.appearance.update'), [
                'template_id' => 'vcard',
                'version' => 999, // stale version
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'CONFLICT',
                ],
            ]);
    }

    public function test_appearance_update_does_not_mutate_unrelated_profile_data_or_blocks(): void
    {
        $block = ProfileBlock::create([
            'profile_id' => $this->profile->id,
            'type' => 'link',
            'config' => ['title' => 'Persistent Link', 'url' => 'https://keepme.com'],
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson(route('api.v1.profile.appearance.update'), [
                'template_id' => 'vcard',
                'version' => (int) $this->profile->version,
            ]);

        $response->assertStatus(200);

        $freshProfile = $this->profile->fresh();
        $this->assertEquals('vcard', $freshProfile->template_id);
        $this->assertEquals('Original Bio', $freshProfile->bio);
        $this->assertEquals('Theme Guy', $freshProfile->display_name);

        $this->assertDatabaseHas('profile_blocks', [
            'id' => $block->id,
            'profile_id' => $this->profile->id,
            'type' => 'link',
        ]);
    }

    public function test_public_profile_returns_template_id_and_theme_tokens(): void
    {
        $themeTokens = [
            'color_background' => '#000000',
            'color_surface' => '#111111',
            'color_text_primary' => '#ffffff',
            'color_text_secondary' => '#888888',
            'color_accent' => '#ff0055',
            'font_family' => 'poppins',
            'button_radius' => 'pill',
            'button_style' => 'solid',
            'animation' => 'fade',
        ];

        $this->profile->update([
            'template_id' => 'vcard',
            'theme_tokens' => $themeTokens,
        ]);

        $response = $this->getJson(route('api.v1.public.profile', ['username' => 'themeguy']));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'username' => 'themeguy',
                    'template_id' => 'vcard',
                    'theme_tokens' => $themeTokens,
                ],
            ]);
    }
}
