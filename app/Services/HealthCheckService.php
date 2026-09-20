<?php

namespace App\Services;

use App\Contracts\ErrorTrackingProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class HealthCheckService
{
    public function __construct(
        protected QueueMonitoringService $queueService,
        protected ?ErrorTrackingProviderInterface $errorTracker = null
    ) {
        $this->errorTracker = $errorTracker ?? app(ErrorTrackingProviderInterface::class);
    }

    /**
     * Public, safe health check summary (never leaks secrets or infrastructure details).
     *
     * @return array{
     *   status: string,
     *   timestamp: string,
     *   version: string,
     *   checks: array<string, string>
     * }
     */
    public function getPublicHealth(): array
    {
        $checks = $this->runAllChecks();

        $overallStatus = 'healthy';
        $summary = [];

        foreach ($checks as $component => $data) {
            $summary[$component] = $data['status'];
            if ($data['status'] === 'unhealthy') {
                $overallStatus = 'unhealthy';
            } elseif ($data['status'] === 'degraded' && $overallStatus !== 'unhealthy') {
                $overallStatus = 'degraded';
            }
        }

        return [
            'status' => $overallStatus,
            'timestamp' => now()->toIso8601String(),
            'version' => 'v1',
            'checks' => $summary,
        ];
    }

    /**
     * Detailed admin health check with latencies, probe timings, and operational status.
     *
     * @return array<string, mixed>
     */
    public function getAdminHealth(): array
    {
        $checks = $this->runAllChecks();

        $overallStatus = 'healthy';
        foreach ($checks as $data) {
            if ($data['status'] === 'unhealthy') {
                $overallStatus = 'unhealthy';
                break;
            } elseif ($data['status'] === 'degraded') {
                $overallStatus = 'degraded';
            }
        }

        return [
            'status' => $overallStatus,
            'timestamp' => now()->toIso8601String(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'environment' => app()->environment(),
            'checks' => $checks,
        ];
    }

    /**
     * Run all component probes.
     *
     * @return array<string, array{status: string, latency_ms?: float, details?: string}>
     */
    public function runAllChecks(): array
    {
        return [
            'application' => $this->checkApplication(),
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
        ];
    }

    /**
     * Check Application configuration.
     *
     * @return array{status: string, details?: string}
     */
    public function checkApplication(): array
    {
        if (empty(config('app.key'))) {
            return [
                'status' => 'unhealthy',
                'details' => 'Application key is missing',
            ];
        }

        return [
            'status' => 'healthy',
            'details' => 'Application configured',
        ];
    }

    /**
     * Check Database connectivity and response time.
     *
     * @return array{status: string, latency_ms: float, details?: string}
     */
    public function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            DB::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => $latency > 500 ? 'degraded' : 'healthy',
                'latency_ms' => $latency,
                'details' => 'Connection operational',
            ];
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $start) * 1000, 2);
            Log::error('[HealthCheckService] Database probe failed: ' . $e->getMessage());

            return [
                'status' => 'unhealthy',
                'latency_ms' => $latency,
                'details' => 'Database query failed',
            ];
        }
    }

    /**
     * Check Cache read/write/delete.
     *
     * @return array{status: string, latency_ms: float, details?: string}
     */
    public function checkCache(): array
    {
        $start = microtime(true);
        $key = 'health_probe_' . bin2hex(random_bytes(4));

        try {
            Cache::put($key, 'ok', 10);
            $val = Cache::get($key);
            Cache::forget($key);

            $latency = round((microtime(true) - $start) * 1000, 2);

            if ($val !== 'ok') {
                return [
                    'status' => 'unhealthy',
                    'latency_ms' => $latency,
                    'details' => 'Cache value mismatch',
                ];
            }

            return [
                'status' => $latency > 200 ? 'degraded' : 'healthy',
                'latency_ms' => $latency,
                'details' => 'Cache read/write operational',
            ];
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $start) * 1000, 2);
            Log::error('[HealthCheckService] Cache probe failed: ' . $e->getMessage());

            return [
                'status' => 'unhealthy',
                'latency_ms' => $latency,
                'details' => 'Cache operation failed',
            ];
        }
    }

    /**
     * Check Queue health via QueueMonitoringService.
     *
     * @return array{status: string, pending_jobs: int, failed_jobs: int, details?: string}
     */
    public function checkQueue(): array
    {
        try {
            $queueHealth = $this->queueService->getQueueHealth();

            return [
                'status' => $queueHealth['status'],
                'pending_jobs' => $queueHealth['pending_jobs'],
                'failed_jobs' => $queueHealth['failed_jobs'],
                'details' => "Queue is {$queueHealth['status']} ({$queueHealth['pending_jobs']} pending)",
            ];
        } catch (\Throwable $e) {
            Log::error('[HealthCheckService] Queue probe failed: ' . $e->getMessage());

            return [
                'status' => 'unhealthy',
                'pending_jobs' => 0,
                'failed_jobs' => 0,
                'details' => 'Queue inspection failed',
            ];
        }
    }

    /**
     * Check Storage disk writability.
     *
     * @return array{status: string, latency_ms: float, details?: string}
     */
    public function checkStorage(): array
    {
        $start = microtime(true);
        $testFile = 'health_probe_' . bin2hex(random_bytes(4)) . '.tmp';

        try {
            Storage::disk('local')->put($testFile, 'health-ok');
            $contents = Storage::disk('local')->get($testFile);
            Storage::disk('local')->delete($testFile);

            $latency = round((microtime(true) - $start) * 1000, 2);

            if ($contents !== 'health-ok') {
                return [
                    'status' => 'unhealthy',
                    'latency_ms' => $latency,
                    'details' => 'Storage read verification failed',
                ];
            }

            return [
                'status' => $latency > 300 ? 'degraded' : 'healthy',
                'latency_ms' => $latency,
                'details' => 'Disk read/write operational',
            ];
        } catch (\Throwable $e) {
            $latency = round((microtime(true) - $start) * 1000, 2);
            Log::error('[HealthCheckService] Storage probe failed: ' . $e->getMessage());

            return [
                'status' => 'unhealthy',
                'latency_ms' => $latency,
                'details' => 'Storage write/read failed',
            ];
        }
    }
}
