<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Seed the initial subscription plans reference data.
     */
    public function run(): void
    {
        $plans = [
            [
                'code' => 'free',
                'slug' => 'free',
                'name' => 'Free',
                'description' => 'Essential link-in-bio profile and standard blocks for individual creators.',
                'price_monthly_cents' => 0,
                'price_yearly_cents' => 0,
                'currency' => 'USD',
                'features' => [
                    'profile_count' => 1,
                    'custom_domain_count' => 0,
                    'advanced_templates' => false,
                    'analytics_history_days' => 7,
                    'image_uploads' => true,
                    'gallery_blocks' => false,
                    'video_blocks' => false,
                    'music_blocks' => false,
                    'booking_blocks' => false,
                    'contact_forms' => true,
                    'advanced_analytics' => false,
                    'remove_branding' => false,
                ],
                'limits' => [
                    'max_blocks' => 20,
                    'max_social_links' => 10,
                ],
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'pro',
                'slug' => 'pro',
                'name' => 'Pro',
                'description' => 'For creators and power users requiring custom domains, advanced media blocks, and custom branding.',
                'price_monthly_cents' => 900,
                'price_yearly_cents' => 8400,
                'currency' => 'USD',
                'features' => [
                    'profile_count' => 3,
                    'custom_domain_count' => 1,
                    'advanced_templates' => true,
                    'analytics_history_days' => 90,
                    'image_uploads' => true,
                    'gallery_blocks' => true,
                    'video_blocks' => true,
                    'music_blocks' => true,
                    'booking_blocks' => true,
                    'contact_forms' => true,
                    'advanced_analytics' => true,
                    'remove_branding' => true,
                ],
                'limits' => [
                    'max_blocks' => 100,
                    'max_social_links' => 30,
                ],
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'code' => 'business',
                'slug' => 'business',
                'name' => 'Business',
                'description' => 'For teams and businesses managing multiple brand profiles and custom domains.',
                'price_monthly_cents' => 2900,
                'price_yearly_cents' => 27600,
                'currency' => 'USD',
                'features' => [
                    'profile_count' => 10,
                    'custom_domain_count' => 10,
                    'advanced_templates' => true,
                    'analytics_history_days' => 365,
                    'image_uploads' => true,
                    'gallery_blocks' => true,
                    'video_blocks' => true,
                    'music_blocks' => true,
                    'booking_blocks' => true,
                    'contact_forms' => true,
                    'advanced_analytics' => true,
                    'remove_branding' => true,
                ],
                'limits' => [
                    'max_blocks' => 500,
                    'max_social_links' => 100,
                ],
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $planData) {
            Plan::updateOrCreate(
                ['slug' => $planData['slug']],
                $planData
            );
        }
    }
}
