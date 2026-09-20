<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\ProfileBlock;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Regular Demo User
        $user = User::where('email', 'demo@Digicardo.app')->first();
        if (!$user) {
            $user = User::create([
                'id' => (string) Str::ulid(),
                'name' => 'Alex Rivers',
                'email' => 'demo@Digicardo.app',
                'password' => Hash::make('Password123!'),
                'role' => 'user',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);
        } else {
            $user->update([
                'name' => 'Alex Rivers',
                'password' => Hash::make('Password123!'),
                'role' => 'user',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);
        }

        // 1b. Create Platform Administrator User
        $admin = User::where('email', 'admin@Digicardo.app')->first();
        if (!$admin) {
            User::create([
                'id' => (string) Str::ulid(),
                'name' => 'Digicardo Admin',
                'email' => 'admin@Digicardo.app',
                'password' => Hash::make('AdminPass123!'),
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);
        } else {
            $admin->update([
                'name' => 'Digicardo Admin',
                'password' => Hash::make('AdminPass123!'),
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);
        }

        // 2. Create Demo Profile
        $profile = Profile::where('user_id', $user->id)->first();
        if (!$profile) {
            $profile = Profile::create([
                'id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'username' => 'demo',
                'display_name' => 'Alex Rivers',
                'bio' => 'Digital Creator, Designer & Tech Explorer 🚀 Exploring the future of web & media.',
                'template_id' => 'vcard',
                'is_public' => true,
                'version' => 1,
                'theme_tokens' => [
                    'color_background' => '#090d16',
                    'color_surface' => '#111827',
                    'color_text_primary' => '#f9fafb',
                    'color_text_secondary' => '#9ca3af',
                    'color_accent' => '#6366f1',
                    'font_family' => 'inter',
                    'button_radius' => 'medium',
                    'button_style' => 'solid',
                    'animation' => 'fade',
                ],
            ]);
        } else {
            $profile->update([
                'username' => 'demo',
                'display_name' => 'Alex Rivers',
                'bio' => 'Digital Creator, Designer & Tech Explorer 🚀 Exploring the future of web & media.',
                'template_id' => 'vcard',
                'is_public' => true,
            ]);
        }

        // 3. Clean and recreate demo blocks
        ProfileBlock::where('profile_id', $profile->id)->delete();

        // Header block
        ProfileBlock::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $profile->id,
            'type' => 'heading',
            'config' => [
                'text' => 'Featured Projects & Links',
                'level' => 'h2',
            ],
            'sort_order' => 0,
            'is_visible' => true,
            'version' => 1,
        ]);

        // Link block
        ProfileBlock::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $profile->id,
            'type' => 'link',
            'config' => [
                'title' => 'My Portfolio & Design Case Studies',
                'url' => 'https://github.com',
            ],
            'sort_order' => 1,
            'is_visible' => true,
            'version' => 1,
        ]);

        // CTA block
        ProfileBlock::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $profile->id,
            'type' => 'cta',
            'config' => [
                'title' => 'Subscribe to Weekly Tech Insights',
                'description' => 'Get curated deep-dives into modern web development and SaaS engineering every Friday.',
                'button_label' => 'Join Newsletter Free',
                'url' => 'https://Digicardo.app',
                'style' => 'gradient',
                'size' => 'large',
            ],
            'sort_order' => 2,
            'is_visible' => true,
            'version' => 1,
        ]);

        // Social block
        ProfileBlock::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $profile->id,
            'type' => 'social',
            'config' => [
                'platform' => 'youtube',
                'url' => 'https://youtube.com',
            ],
            'sort_order' => 3,
            'is_visible' => true,
            'version' => 1,
        ]);

        // Video block
        ProfileBlock::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $profile->id,
            'type' => 'video',
            'config' => [
                'provider' => 'youtube',
                'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'title' => 'Latest Project Walkthrough',
                'embed_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            ],
            'sort_order' => 4,
            'is_visible' => true,
            'version' => 1,
        ]);

        // Booking block
        ProfileBlock::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $profile->id,
            'type' => 'booking',
            'config' => [
                'provider' => 'calendly',
                'url' => 'https://calendly.com',
                'title' => 'Book a 1-on-1 Consultation',
                'display_mode' => 'button',
            ],
            'sort_order' => 5,
            'is_visible' => true,
            'version' => 1,
        ]);

        // FAQ block
        ProfileBlock::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $profile->id,
            'type' => 'faq',
            'config' => [
                'title' => 'Frequently Asked Questions',
                'items' => [
                    [
                        'id' => 'faq-1',
                        'question' => 'What services do you offer?',
                        'answer' => 'I provide full-stack SaaS engineering, UI/UX architecture, and technical consulting.',
                    ],
                    [
                        'id' => 'faq-2',
                        'question' => 'How can we collaborate?',
                        'answer' => 'Use the booking link above or send a message via the contact form below.',
                    ],
                ],
            ],
            'sort_order' => 6,
            'is_visible' => true,
            'version' => 1,
        ]);

        // Contact block
        ProfileBlock::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $profile->id,
            'type' => 'contact',
            'config' => [
                'title' => 'Send Me a Direct Message',
                'description' => 'Have a question or proposal? Drop your note below.',
                'button_label' => 'Send Message',
                'name_enabled' => true,
                'email_enabled' => true,
                'phone_enabled' => false,
                'message_enabled' => true,
            ],
            'sort_order' => 7,
            'is_visible' => true,
            'version' => 1,
        ]);

        // Sample Contact Messages
        \App\Models\ContactSubmission::where('profile_id', $profile->id)->forceDelete();

        \App\Models\ContactSubmission::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $profile->id,
            'name' => 'Sarah Jenkins',
            'email' => 'sarah@techcorp.io',
            'message' => 'Hi Alex! We would love to collaborate on a design sprint for our new SaaS product.',
            'ip_hash' => hash_hmac('sha256', '192.168.1.5', config('app.key')),
            'created_at' => \Carbon\Carbon::now()->subHours(2),
        ]);

        \App\Models\ContactSubmission::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $profile->id,
            'name' => 'Marcus Vance',
            'email' => 'marcus@venturecapital.com',
            'message' => 'Loved your portfolio breakdown on YouTube. Do you have time for an intro call this week?',
            'ip_hash' => hash_hmac('sha256', '192.168.1.6', config('app.key')),
            'created_at' => \Carbon\Carbon::now()->subDay(),
        ]);

        // Sample Analytics Daily Metrics for the past 7 days
        $today = \Carbon\Carbon::today();
        $firstBlock = ProfileBlock::where('profile_id', $profile->id)->where('type', 'link')->first();

        for ($i = 6; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i)->toDateString();
            $views = rand(50, 140);
            $unique = (int) ($views * 0.72);
            $clicks = rand(20, 60);

            \App\Models\AnalyticsDailyMetric::updateOrCreate(
                ['profile_id' => $profile->id, 'block_id' => '', 'date' => $date, 'event_type' => 'profile_view'],
                ['id' => (string) Str::ulid(), 'total_count' => $views, 'unique_count' => $unique]
            );

            if ($firstBlock) {
                \App\Models\AnalyticsDailyMetric::updateOrCreate(
                    ['profile_id' => $profile->id, 'block_id' => $firstBlock->id, 'date' => $date, 'event_type' => 'link_click'],
                    ['id' => (string) Str::ulid(), 'total_count' => $clicks, 'unique_count' => 0]
                );
            }
        }
    }
}
