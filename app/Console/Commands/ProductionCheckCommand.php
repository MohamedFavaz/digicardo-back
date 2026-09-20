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

class ProductionCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'Digicardo:production-check {--json : Output report as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform production deployment readiness and infrastructure health checks';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('====================================================');
        $this->info('  Digicardo — Production Readiness Check');
        $this->info('====================================================');
        $this->newLine();

        $results = [];
        $hasCriticalFailures = false;

        // 1. APP_KEY
        $hasKey = !empty(config('app.key'));
        $results[] = [
            'name' => 'APP_KEY Configuration',
            'status' => $hasKey ? 'PASS' : 'FAIL',
            'details' => $hasKey ? 'Application encryption key configured' : 'APP_KEY is missing or empty',
            'critical' => true,
        ];
        if (!$hasKey)
            $hasCriticalFailures = true;

        // 2. APP_DEBUG
        $debug = config('app.debug');
        $isProd = app()->environment('production');
        $debugSafe = !($isProd && $debug);
        $results[] = [
            'name' => 'Debug Mode',
            'status' => $debugSafe ? 'PASS' : 'WARNING',
            'details' => $debugSafe ? ($debug ? 'Debug enabled (non-production)' : 'Debug disabled') : 'APP_DEBUG is TRUE in production environment',
            'critical' => false,
        ];

        // 3. Database Connectivity
        $dbStatus = 'FAIL';
        $dbDetails = 'Database connection failed';
        try {
            DB::select('SELECT 1');
            $dbStatus = 'PASS';
            $dbDetails = 'Database connected successfully';
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
        $cacheDetails = 'Cache probe failed';
        try {
            $key = 'prod_check_' . bin2hex(random_bytes(4));
            Cache::put($key, '1', 5);
            if (Cache::get($key) === '1') {
                Cache::forget($key);
                $cacheStatus = 'PASS';
                $cacheDetails = 'Cache read/write verified (' . config('cache.default') . ')';
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
        $storageStatus = 'FAIL';
        $storageDetails = 'Storage disk probe failed';
        try {
            $testFile = 'prod_check_' . bin2hex(random_bytes(4)) . '.tmp';
            Storage::disk('local')->put($testFile, 'ready');
            if (Storage::disk('local')->get($testFile) === 'ready') {
                Storage::disk('local')->delete($testFile);
                $storageStatus = 'PASS';
                $storageDetails = 'Storage disk read/write verified';
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

        // 6. Queue Driver
        $queueDriver = config('queue.default');
        $queueStatus = ($isProd && $queueDriver === 'sync') ? 'WARNING' : 'PASS';
        $queueDetails = "Queue driver configured ({$queueDriver})";
        if ($isProd && $queueDriver === 'sync') {
            $queueDetails = 'Queue connection is "sync" in production; background jobs will run synchronously';
        }
        $results[] = [
            'name' => 'Queue Configuration',
            'status' => $queueStatus,
            'details' => $queueDetails,
            'critical' => false,
        ];

        // 7. Email Provider
        $emailProvider = app(EmailProviderInterface::class);
        $mailer = config('mail.default');
        $results[] = [
            'name' => 'Email Delivery Provider',
            'status' => 'PASS',
            'details' => 'Bound to ' . class_basename($emailProvider) . " (mailer: {$mailer})",
            'critical' => false,
        ];

        // 8. Subscription Provider
        $subscriptionProvider = app(SubscriptionProviderInterface::class);
        $subConfig = config('services.subscription.provider', 'null');
        $results[] = [
            'name' => 'Subscription Provider',
            'status' => 'PASS',
            'details' => 'Bound to ' . class_basename($subscriptionProvider) . " (configured: {$subConfig})",
            'critical' => false,
        ];

        // 9. Custom Domain Hostname Provider
        $hostnameProvider = app(CustomHostnameProviderInterface::class);
        $results[] = [
            'name' => 'Domain Hostname Provider',
            'status' => 'PASS',
            'details' => 'Bound to ' . class_basename($hostnameProvider),
            'critical' => false,
        ];

        // 10. Error Tracking Provider
        $errorTracker = app(ErrorTrackingProviderInterface::class);
        $trackerDriver = config('observability.error_tracking.driver', 'null');
        $results[] = [
            'name' => 'Error Tracking Provider',
            'status' => $trackerDriver === 'null' && $isProd ? 'WARNING' : 'PASS',
            'details' => 'Bound to ' . class_basename($errorTracker) . " (driver: {$trackerDriver})",
            'critical' => false,
        ];

        // Render Output
        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'success' => !$hasCriticalFailures,
                'timestamp' => now()->toIso8601String(),
                'checks' => $results,
            ], JSON_PRETTY_PRINT));
            return $hasCriticalFailures ? self::FAILURE : self::SUCCESS;
        }

        foreach ($results as $check) {
            $symbol = match ($check['status']) {
                'PASS' => '<fg=green>✓ PASS</>',
                'WARNING' => '<fg=yellow>⚠ WARN</>',
                'FAIL' => '<fg=red>✗ FAIL</>',
            };

            $this->line(sprintf(' %-10s %-30s <fg=gray>%s</>', $symbol, $check['name'], $check['details']));
        }

        $this->newLine();
        if ($hasCriticalFailures) {
            $this->error('Production readiness check FAILED with critical issues. Fix failed items before deploying.');
            return self::FAILURE;
        }

        $this->info('Production readiness check PASSED. System is ready for deployment.');
        return self::SUCCESS;
    }
}
