<?php

namespace App\Services;

use App\Contracts\ErrorTrackingProviderInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QueueMonitoringService
{
    public function __construct(
        protected ?ErrorTrackingProviderInterface $errorTracker = null
    ) {
        $this->errorTracker = $errorTracker ?? app(ErrorTrackingProviderInterface::class);
    }

    /**
     * Get operational health status and metrics for background queues.
     *
     * @return array{
     *   status: string,
     *   pending_jobs: int,
     *   failed_jobs: int,
     *   failed_last_hour: int,
     *   oldest_pending_seconds: int,
     *   queues: list<array{name: string, pending_jobs: int, status: string}>
     * }
     */
    public function getQueueHealth(): array
    {
        $pendingJobsCount = 0;
        $oldestPendingSeconds = 0;
        $queueBreakdown = [];

        try {
            $pendingJobs = DB::table('jobs')->get(['id', 'queue', 'created_at', 'available_at']);
            $pendingJobsCount = $pendingJobs->count();

            if ($pendingJobsCount > 0) {
                $oldestAvailable = $pendingJobs->min('available_at');
                if ($oldestAvailable) {
                    $oldestPendingSeconds = max(0, now()->timestamp - (int) $oldestAvailable);
                }
            }

            // Group by queue name
            $grouped = $pendingJobs->groupBy('queue');
            foreach ($grouped as $queueName => $items) {
                $count = $items->count();
                $queueBreakdown[] = [
                    'name' => (string) $queueName,
                    'pending_jobs' => $count,
                    'status' => $count > config('observability.queue.pending_degraded_threshold', 50) ? 'degraded' : 'healthy',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('[QueueMonitoringService] Failed reading jobs table: ' . $e->getMessage());
        }

        // Add default queue entry if empty
        if (empty($queueBreakdown)) {
            $queueBreakdown[] = [
                'name' => 'default',
                'pending_jobs' => 0,
                'status' => 'healthy',
            ];
        }

        $failedJobsCount = 0;
        $failedLastHour = 0;

        try {
            $failedJobsCount = DB::table('failed_jobs')->count();
            $failedLastHour = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subHour())
                ->count();
        } catch (\Throwable $e) {
            Log::warning('[QueueMonitoringService] Failed reading failed_jobs table: ' . $e->getMessage());
        }

        // Determine health status
        $config = config('observability.queue');
        $status = 'healthy';

        if (
            $pendingJobsCount >= ($config['pending_critical_threshold'] ?? 500) ||
            $oldestPendingSeconds >= ($config['max_age_critical_seconds'] ?? 900) ||
            $failedLastHour >= ($config['failed_last_hour_critical'] ?? 25)
        ) {
            $status = 'critical';
        } elseif (
            $pendingJobsCount >= ($config['pending_degraded_threshold'] ?? 50) ||
            $oldestPendingSeconds >= ($config['max_age_degraded_seconds'] ?? 180) ||
            $failedLastHour >= ($config['failed_last_hour_degraded'] ?? 5)
        ) {
            $status = 'degraded';
        }

        return [
            'status' => $status,
            'pending_jobs' => $pendingJobsCount,
            'failed_jobs' => $failedJobsCount,
            'failed_last_hour' => $failedLastHour,
            'oldest_pending_seconds' => $oldestPendingSeconds,
            'queues' => $queueBreakdown,
        ];
    }

    /**
     * List sanitized failed jobs with pagination.
     */
    public function listFailedJobs(int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        try {
            $total = DB::table('failed_jobs')->count();
            $items = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->forPage($page, $perPage)
                ->get();

            $sanitized = $items->map(function ($job) {
                return $this->sanitizeFailedJobRecord($job);
            });

            return new Paginator($sanitized, $total, $perPage, $page, [
                'path' => request()->url(),
                'query' => request()->query(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[QueueMonitoringService] listFailedJobs query failed: ' . $e->getMessage());
            return new Paginator(collect(), 0, $perPage, $page);
        }
    }

    /**
     * Retry a specific failed job.
     */
    public function retryFailedJob(string|int $id): bool
    {
        $exitCode = Artisan::call('queue:retry', [
            'id' => [(string) $id],
        ]);

        Log::info('[QueueMonitoringService] Admin retried failed job', ['job_id' => $id]);

        return $exitCode === 0;
    }

    /**
     * Retry all failed jobs.
     */
    public function retryAllFailedJobs(): int
    {
        $initialCount = DB::table('failed_jobs')->count();
        Artisan::call('queue:retry', ['id' => ['all']]);
        $remainingCount = DB::table('failed_jobs')->count();

        $retried = max(0, $initialCount - $remainingCount);
        Log::info('[QueueMonitoringService] Admin retried all failed jobs', ['retried_count' => $retried]);

        return $retried;
    }

    /**
     * Permanently forget/discard a specific failed job.
     */
    public function forgetFailedJob(string|int $id): bool
    {
        $exitCode = Artisan::call('queue:forget', [
            'id' => (string) $id,
        ]);

        Log::info('[QueueMonitoringService] Admin discarded failed job', ['job_id' => $id]);

        return $exitCode === 0;
    }

    /**
     * Flush all failed jobs.
     */
    public function flushFailedJobs(): bool
    {
        $exitCode = Artisan::call('queue:flush');

        Log::info('[QueueMonitoringService] Admin flushed all failed jobs');

        return $exitCode === 0;
    }

    /**
     * Sanitize a failed job DB record before API serialization.
     *
     * @param object $job
     * @return array<string, mixed>
     */
    public function sanitizeFailedJobRecord(object $job): array
    {
        $jobName = 'Unknown Job';
        try {
            $payload = json_decode((string) $job->payload, true);
            $jobName = $payload['displayName'] ?? $payload['job'] ?? 'Queued Job';
        } catch (\Throwable) {
            // Keep default
        }

        // Sanitize exception message to strip secrets/passwords/tokens
        $exceptionText = (string) ($job->exception ?? '');
        $sanitizedException = $this->sanitizeErrorText($exceptionText);

        return [
            'id' => (string) $job->id,
            'uuid' => $job->uuid ?? null,
            'connection' => $job->connection ?? 'database',
            'queue' => $job->queue ?? 'default',
            'name' => $jobName,
            'failed_at' => $job->failed_at ?? null,
            'exception_preview' => substr($sanitizedException, 0, 500),
        ];
    }

    /**
     * Strip sensitive keys from text.
     */
    protected function sanitizeErrorText(string $text): string
    {
        // Redact bearer tokens
        $text = preg_replace('/Bearer\s+[A-Za-z0-9_\-\.]+/i', 'Bearer [REDACTED]', $text) ?? $text;

        // Redact passwords in connection strings or JSON
        $text = preg_replace('/(?:"password"|password)\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|\S+)/i', '"password":"[REDACTED]"', $text) ?? $text;

        // Redact API keys / secrets
        $text = preg_replace('/(?:"secret"|secret|api_key)\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|\S+)/i', '"secret":"[REDACTED]"', $text) ?? $text;

        return $text;
    }
}
