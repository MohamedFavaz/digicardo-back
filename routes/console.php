<?php

use App\Models\User;
use App\Models\Profile;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Command to reset admin password
Artisan::command('admin:reset {password=AdminPass123!}', function ($password) {
    $user = User::firstOrCreate(
        ['email' => 'admin@Digicardo.app'],
        ['name' => 'Digicardo Admin', 'role' => 'admin']
    );
    $user->password = Hash::make($password);
    $user->save();

    $this->info("Admin ({$user->email}) password reset to: {$password}");
});

// Command to create missing profiles for all users
Artisan::command('users:fix-profiles', function () {
    $users = User::all();
    $count = 0;

    foreach ($users as $user) {
        if (!$user->profile) {
            $baseSlug = Str::slug(explode('@', $user->email)[0]) ?: 'user';
            $uniqueUsername = $baseSlug . '-' . rand(100, 9999);

            Profile::create([
                'user_id' => $user->id,
                'username' => $uniqueUsername,
                'display_name' => $user->name ?: 'Creator',
                'bio' => 'Welcome to my page!',
                'template_id' => 'vcard',
                'theme_tokens' => [
                    'color_background' => '#ffffff',
                    'color_surface' => '#f8fafc',
                    'color_text_primary' => '#0f172a',
                    'color_text_secondary' => '#64748b',
                    'color_accent' => '#6366f1',
                    'font_family' => 'inter',
                    'button_radius' => 'medium',
                    'button_style' => 'solid',
                    'animation' => 'none',
                ],
                'is_public' => true,
                'version' => 1,
            ]);

            $count++;
            $this->info("Created profile for {$user->email} (username: {$uniqueUsername})");
        }
    }

    $this->info("Complete! Fixed {$count} profile(s).");
});
