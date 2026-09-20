<?php

namespace App\Console\Commands;

use App\Contracts\CustomHostnameProviderInterface;
use App\Contracts\EmailProviderInterface;
use App\Contracts\ErrorTrackingProviderInterface;
use App\Contracts\SubscriptionProviderInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SystemCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system:check {--json : Output report as JSON} {--strict : Enforce strict production requirements}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform automated production readiness and system health validation without exposing secrets';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isStrict = (bool) $this->option('strict');
        $isProd = app()->environment('production') || $isStrict;

        if (!$this->option('json')) {
            $this->info('====================================================');
            $this->info('  Digicardo — System & Production Readiness Check   ');
            $this->info('====================================================');
            $this->line(' Environment: <fg=cyan>' . app()->environment() . '</>' . ($isStrict ? ' <fg=yellow>(Strict Mode Enforced)</>' : ''));
            $this->newLine();
        }

        $results = [];
        $hasCriticalFailures = false;

        // 1. Application Encryption Key
        $hasKey = !empty(config('app.key'));
        $keyLengthValid = $hasKey && (str_starts_with(config('app.key'), 'base64:') ? strlen(base64_decode(substr(config('app.key'), 7))) === 32 : strlen(config('app.key')) === 32);
        $keyStatus = ($hasKey && $keyLengthValid) ? 'PASS' : 'FAIL';
        $keyDetails = ($hasKey && $keyLengthValid)
            ? 'Application encryption key configured (32-byte AES-256)'
            : 'APP_KEY is missing, malformed, or invalid length';

        $results[] = [
            'name' => 'Application Configuration',
            'status' => $keyStatus,
            'details' => $keyDetails,
            'critical' => true,
        ];
        if ($keyStatus === 'FAIL') {
            $hasCriticalFailures = true;
        }

        // 2. Debug Mode Security
        $debug = config('app.debug');
        $debugSafe = !($isProd && $debug);
        $debugStatus = $debugSafe ? 'PASS' : ($isStrict ? 'FAIL' : 'WARNING');
        $results[] = [
            'name' => 'Debug Mode Security',
            'status' => $debugStatus,
            'details' => $debugSafe ? ($debug ? 'Debug enabled (non-production)' : 'Debug disabled (production safe)') : 'APP_DEBUG is enabled in production environment',
            'critical' => $isStrict,
        ];
        if ($debugStatus === 'FAIL') {
            $hasCriticalFailures = true;
        }

        // 3. Database Connectivity & Query Health
        $dbStatus = 'FAIL';
        $dbDetails = 'Database connection failed';
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);
            $dbStatus = $latency > 500 ? 'WARNING' : 'PASS';
            $dbDetails = 'Connected successfully (' . config('database.default') . ', ' . $latency . 'ms)';
        } catch (\Throwable $e) {
            $hasCriticalFailures = true;
        }
        $results[] = [
            'name' => 'Database Connectivity',
            'status' => $dbStatus,
            'details' => $dbDetails,
            'critical' => true,
        ];

        // 4. Cache Subsystem
        $cacheStatus = 'FAIL';
        $cacheDetails = 'Cache read/write probe failed';
        try {
            $key = 'system_check_' . bin2hex(random_bytes(4));
            Cache::put($key, 'ok', 10);
            if (Cache::get($key) === 'ok') {
                Cache::forget($key);
                $cacheStatus = 'PASS';
                $cacheDetails = 'Cache operational (' . config('cache.default') . ')';
            }
        } catch (\Throwable $e) {
            $hasCriticalFailures = true;
        }
        $results[] = [
            'name' => 'Cache Subsystem',
            'status' => $cacheStatus,
            'details' => $cacheDetails,
            'critical' => true,
        ];

        // 5. Storage Subsystem
        $storageDisk = config('filesystems.default', 'local');
        $storageStatus = 'FAIL';
        $storageDetails = 'Storage probe failed';
        try {
            $testFile = 'system_check_' . bin2hex(random_bytes(4)) . '.tmp';
            Storage::disk($storageDisk)->put($testFile, 'verified');
            if (Storage::disk($storageDisk)->get($testFile) === 'verified') {
                Storage::disk($storageDisk)->delete($testFile);
                $storageStatus = 'PASS';
                $storageDetails = "Storage operational ({$storageDisk})";
            }
        } catch (\Throwable $e) {
            $hasCriticalFailures = true;
        }
        $results[] = [
            'name' => 'Storage Subsystem',
            'status' => $storageStatus,
            'details' => $storageDetails,
            'critical' => true,
        ];

        // 6. Queue Configuration
        $queueDriver = config('queue.default');
        $queueStatus = ($isProd && $queueDriver === 'sync') ? ($isStrict ? 'FAIL' : 'WARNING') : 'PASS';
        $queueDetails = "Queue connection: {$queueDriver}";
        if ($isProd && $queueDriver === 'sync') {
            $queueDetails = 'Queue connection is "sync" in production; background jobs run synchronously';
        }
        $results[] = [
            'name' => 'Queue Configuration',
            'status' => $queueStatus,
            'details' => $queueDetails,
            'critical' => $isStrict && $queueDriver === 'sync',
        ];
        if ($queueStatus === 'FAIL') {
            $hasCriticalFailures = true;
        }

        // 7. Email Delivery Provider
        $emailProvider = app(EmailProviderInterface::class);
        $mailer = config('mail.default');
        $emailStatus = ($isProd && $mailer === 'log') ? 'WARNING' : 'PASS';
        $emailDetails = 'Bound to ' . class_basename($emailProvider) . " (mailer: {$mailer})";
        if ($isProd && $mailer === 'log') {
            $emailDetails = 'Mailer is set to "log" in production; emails will not be sent to recipients';
        }
        $results[] = [
            'name' => 'Email Delivery Configuration',
            'status' => $emailStatus,
            'details' => $emailDetails,
            'critical' => false,
        ];

        // 8. Subscription & Payment Provider
        $subscriptionProvider = app(SubscriptionProviderInterface::class);
        $subConfig = config('services.subscription.provider', 'null');
        $stripeKey = config('services.stripe.key');
        $stripeSecret = config('services.stripe.secret');
        $stripeWebhook = config('services.stripe.webhook_secret');

        $subStatus = 'PASS';
        $subDetails = 'Bound to ' . class_basename($subscriptionProvider) . " (configured: {$subConfig})";

        if ($subConfig === 'stripe') {
            if (empty($stripeKey) || empty($stripeSecret) || empty($stripeWebhook)) {
                $subStatus = $isProd ? 'FAIL' : 'WARNING';
                $subDetails = 'Stripe provider selected but STRIPE_KEY, STRIPE_SECRET, or STRIPE_WEBHOOK_SECRET is missing';
                if ($isProd) {
                    $hasCriticalFailures = true;
                }
            } else {
                $subDetails = 'Stripe provider configured with API & Webhook secrets';
            }
        } elseif ($isProd && $subConfig === 'null') {
            $subStatus = 'WARNING';
            $subDetails = 'Subscription provider is "null"; paid checkout is disabled';
        }

        $results[] = [
            'name' => 'Subscription Provider Configuration',
            'status' => $subStatus,
            'details' => $subDetails,
            'critical' => ($subConfig === 'stripe' && $isProd && (empty($stripeKey) || empty($stripeSecret))),
        ];

        // 9. Custom Domain Hostname Provider
        $hostnameProvider = app(CustomHostnameProviderInterface::class);
        $cfZone = config('services.cloudflare.zone_id');
        $cfToken = config('services.cloudflare.api_token');
        $hostStatus = 'PASS';
        $hostDetails = 'Bound to ' . class_basename($hostnameProvider);

        if (!empty($cfZone) || !empty($cfToken)) {
            if (empty($cfZone) || empty($cfToken)) {
                $hostStatus = 'WARNING';
                $hostDetails = 'Cloudflare partially configured (either ZONE_ID or API_TOKEN is missing)';
            } else {
                $hostDetails = 'Cloudflare custom hostname credentials configured';
            }
        }
        $results[] = [
            'name' => 'Domain Hostname Configuration',
            'status' => $hostStatus,
            'details' => $hostDetails,
            'critical' => false,
        ];

        // 10. Observability & Error Tracking
        $errorTracker = app(ErrorTrackingProviderInterface::class);
        $trackerDriver = config('observability.error_tracking.driver', 'null');
        $obsStatus = ($trackerDriver === 'null' && $isProd) ? 'WARNING' : 'PASS';
        $obsDetails = 'Bound to ' . class_basename($errorTracker) . " (driver: {$trackerDriver})";
        $results[] = [
            'name' => 'Observability & Error Tracking',
            'status' => $obsStatus,
            'details' => $obsDetails,
            'critical' => false,
        ];

        // 11. Security & Stateful Domains
        $statefulDomains = config('sanctum.stateful', []);
        $salt = config('services.analytics.daily_salt') ?? env('ANALYTICS_DAILY_SALT');
        $saltValid = !empty($salt) && strlen($salt) >= 16;
        $secStatus = ($saltValid && !empty($statefulDomains)) ? 'PASS' : 'WARNING';
        $secDetails = $saltValid ? 'Sanctum stateful domains & analytics salt configured' : 'Analytics salt is missing or too short (<16 chars)';

        $results[] = [
            'name' => 'Security & Privacy Salts',
            'status' => $secStatus,
            'details' => $secDetails,
            'critical' => false,
        ];

        // JSON Output
        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'success' => !$hasCriticalFailures,
                'environment' => app()->environment(),
                'strict_mode' => $isStrict,
                'timestamp' => now()->toIso8601String(),
                'checks' => $results,
            ], JSON_PRETTY_PRINT));

            return $hasCriticalFailures ? self::FAILURE : self::SUCCESS;
        }

        // Formatted Console Output
        foreach ($results as $check) {
            $symbol = match ($check['status']) {
                'PASS' => '<fg=green>[PASS]</>',
                'WARNING' => '<fg=yellow>[WARN]</>',
                'FAIL' => '<fg=red>[FAIL]</>',
            };

            $this->line(sprintf(' %-8s %-36s <fg=gray>%s</>', $symbol, $check['name'], $check['details']));
        }

        $this->newLine();
        if ($hasCriticalFailures) {
            $this->error('Production readiness check FAILED with critical issues. Resolve all [FAIL] items before deploying.');
            return self::FAILURE;
        }

        $this->info('Production readiness check PASSED. System is configured and ready.');
        return self::SUCCESS;
    }
}
