<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ScheduledTaskMonitoringService
{
    /**
     * Cache key prefix for scheduler run metadata.
     */
    protected const CACHE_PREFIX = 'scheduler_task_meta:';

    /**
     * List all scheduled tasks with execution status and monitoring metadata.
     *
     * @return list<array{
     *   name: string,
     *   command: string,
     *   expression: string,
     *   frequency: string,
     *   last_run_at: string|null,
     *   last_duration_seconds: float|null,
     *   status: string,
     *   next_due_at: string|null
     * }>
     */
    public function getScheduledTasks(): array
    {
        $definedTasks = [
            [
                'name' => 'Queue Worker Processing',
                'command' => 'queue:work database --stop-when-empty --max-time=55 --max-jobs=100',
                'expression' => '* * * * *',
                'frequency' => 'Every minute',
                'expected_interval_minutes' => 1,
            ],
            [
                'name' => 'Subscription Status Reconciliation',
                'command' => 'Digicardo:reconcile-subscriptions',
                'expression' => '0 * * * *',
                'frequency' => 'Hourly',
                'expected_interval_minutes' => 60,
            ],
            [
                'name' => 'Analytics Rollup & Cleanup',
                'command' => 'Digicardo:cleanup-analytics',
                'expression' => '0 2 * * *',
                'frequency' => 'Daily at 02:00 UTC',
                'expected_interval_minutes' => 1440,
            ],
            [
                'name' => 'Orphan Media Storage Cleanup',
                'command' => 'Digicardo:cleanup-orphan-media',
                'expression' => '0 3 * * 0',
                'frequency' => 'Weekly (Sundays at 03:00 UTC)',
                'expected_interval_minutes' => 10080,
            ],
            [
                'name' => 'Database Session Pruning',
                'command' => 'session:prune --hours=720',
                'expression' => '0 4 * * *',
                'frequency' => 'Daily at 04:00 UTC',
                'expected_interval_minutes' => 1440,
            ],
            [
                'name' => 'Failed Job Retention Pruning',
                'command' => 'queue:prune-failed --hours=168',
                'expression' => '0 5 * * *',
                'frequency' => 'Daily at 05:00 UTC',
                'expected_interval_minutes' => 1440,
            ],
        ];

        $results = [];

        foreach ($definedTasks as $task) {
            $meta = $this->getTaskMeta($task['command']);
            $lastRun = $meta['last_run_at'] ?? null;
            $status = $meta['status'] ?? 'pending';

            // If task hasn't run within 2.5x expected interval, mark degraded
            if ($lastRun && $status === 'healthy') {
                $lastRunCarbon = Carbon::parse($lastRun);
                $graceMinutes = max(5, (int) ($task['expected_interval_minutes'] * 2.5));
                if ($lastRunCarbon->addMinutes($graceMinutes)->isPast()) {
                    $status = 'delayed';
                }
            }

            $results[] = [
                'name' => $task['name'],
                'command' => $task['command'],
                'expression' => $task['expression'],
                'frequency' => $task['frequency'],
                'last_run_at' => $lastRun,
                'last_duration_seconds' => $meta['duration_seconds'] ?? null,
                'status' => $status,
                'next_due_at' => $this->calculateNextDue($task['expression']),
            ];
        }

        return $results;
    }

    /**
     * Record execution of a scheduled task.
     */
    public function recordTaskExecution(string $command, float $durationSeconds, bool $success = true, ?string $error = null): void
    {
        $key = self::CACHE_PREFIX . md5($command);

        Cache::put($key, [
            'last_run_at' => now()->toIso8601String(),
            'duration_seconds' => round($durationSeconds, 3),
            'status' => $success ? 'healthy' : 'failed',
            'last_error' => $error,
        ], now()->addDays(7));
    }

    /**
     * Get recorded execution metadata for a command.
     *
     * @return array{last_run_at?: string, duration_seconds?: float, status?: string, last_error?: string|null}
     */
    public function getTaskMeta(string $command): array
    {
        $key = self::CACHE_PREFIX . md5($command);
        return Cache::get($key, []);
    }

    /**
     * Estimate next due timestamp.
     */
    protected function calculateNextDue(string $expression): string
    {
        // Simple approximate calculation for UI display
        if ($expression === '* * * * *') {
            return now()->addMinute()->startOfMinute()->toIso8601String();
        }
        if (str_starts_with($expression, '0 *')) {
            return now()->addHour()->startOfHour()->toIso8601String();
        }
        return now()->tomorrow()->startOfDay()->toIso8601String();
    }
}
