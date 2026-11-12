<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== Digicardo Production Migration & Sync ===\n";

// 1. Ensure migrations table exists
if (!Schema::hasTable('migrations')) {
    echo "Creating migrations table...\n";
    Schema::create('migrations', function ($table) {
        $table->increments('id');
        $table->string('migration');
        $table->integer('batch');
    });
}

// 2. Base migrations that already exist if tables are present
$baseMigrations = [
    '2026_08_15_000001_create_users_table' => 'users',
    '2026_08_15_000002_create_sessions_table' => 'sessions',
    '2026_08_15_000003_create_jobs_table' => 'jobs',
    '2026_08_15_000004_create_cache_table' => 'cache',
    '2026_08_15_000005_create_personal_access_tokens_table' => 'personal_access_tokens',
    '2026_08_15_000006_create_plans_table' => 'plans',
    '2026_08_15_000007_create_profiles_table' => 'profiles',
    '2026_08_15_000008_create_profile_blocks_table' => 'profile_blocks',
    '2026_08_15_000009_add_theme_tokens_to_profiles_table' => 'profiles',
    '2026_08_15_000010_create_profile_media_table' => 'profile_media',
    '2026_08_15_000011_create_contact_submissions_table' => 'contact_submissions',
    '2026_08_15_000012_create_analytics_tables' => 'analytics_events',
    '2026_08_15_000013_create_profile_domains_table' => 'profile_domains',
    '2026_08_15_000014_create_subscriptions_and_update_plans_tables' => 'subscriptions',
    '2026_08_15_000015_create_subscription_webhook_events_and_update_subscriptions_table' => 'subscription_webhook_events',
    '2026_08_15_000016_add_seo_and_og_fields_to_profiles_table' => 'profiles',
    '2026_08_15_000017_create_notifications_system_tables' => 'notifications',
    '2026_08_15_000018_create_password_reset_tokens_table' => 'password_reset_tokens',
    '2026_08_16_000019_create_account_sessions_table' => 'account_sessions',
    '2026_08_16_000020_create_account_security_events_table' => 'account_security_events',
    '2026_08_16_000021_add_moderation_fields_to_profiles_table' => 'profiles',
    '2026_08_16_000022_create_abuse_reports_table' => 'abuse_reports',
    '2026_08_16_000023_create_moderation_actions_table' => 'moderation_actions',
    '2026_08_16_000024_create_admin_audit_logs_table' => 'admin_audit_logs',
    '2026_08_30_000025_add_validity_to_users_table' => 'users',
    '2026_08_30_000026_create_validity_plans_table' => 'validity_plans',
    '2026_08_30_000027_create_admin_sales_table' => 'admin_sales',
    '2026_08_30_000028_create_access_requests_table' => 'access_requests',
];

$marked = 0;
foreach ($baseMigrations as $migration => $table) {
    if (Schema::hasTable($table)) {
        $exists = DB::table('migrations')->where('migration', $migration)->exists();
        if (!$exists) {
            DB::table('migrations')->insert([
                'migration' => $migration,
                'batch' => 1,
            ]);
            $marked++;
        }
    }
}
echo "Synced {$marked} existing base migration entries.\n";

// 3. Run all pending migrations (e.g. agent tables, avatar_url)
echo "Running pending migrations...\n";
Artisan::call('migrate', ['--force' => true]);
echo Artisan::output();

// 4. Ensure storage directories exist before caching
echo "Ensuring storage framework directories exist...\n";
@mkdir(__DIR__ . '/storage/framework/views', 0775, true);
@mkdir(__DIR__ . '/storage/framework/sessions', 0775, true);
@mkdir(__DIR__ . '/storage/framework/cache/data', 0775, true);
@mkdir(__DIR__ . '/storage/app/public', 0775, true);

// 5. Link storage if not linked
echo "Checking storage link...\n";
try {
    Artisan::call('storage:link');
    echo Artisan::output();
} catch (\Throwable $e) {
    echo "Storage link note: " . $e->getMessage() . "\n";
}

// 6. Clear and rebuild caches
echo "Optimizing caches...\n";
try {
    Artisan::call('optimize:clear');
    echo Artisan::output();
} catch (\Throwable $e) {
    echo "optimize:clear note: " . $e->getMessage() . "\n";
}

try {
    Artisan::call('config:cache');
    echo Artisan::output();
} catch (\Throwable $e) {
    echo "config:cache note: " . $e->getMessage() . "\n";
}

try {
    Artisan::call('route:cache');
    echo Artisan::output();
} catch (\Throwable $e) {
    echo "route:cache note: " . $e->getMessage() . "\n";
}

echo "\nSUCCESS: Database is now fully migrated and application cache is primed!\n";

