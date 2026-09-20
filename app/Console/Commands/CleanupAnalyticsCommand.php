<?php

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsUniqueVisitor;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanupAnalyticsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analytics:cleanup {--days=90 : Number of days of raw events to retain}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up raw analytics events and daily visitor records older than the retention threshold, preserving aggregated metrics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $days = 90;
        }

        $cutoff = Carbon::now()->subDays($days);
        $this->info("Cleaning up raw analytics data older than {$days} days (cutoff: {$cutoff->toDateTimeString()})...");

        // Delete raw events
        $eventsCount = AnalyticsEvent::where('occurred_at', '<', $cutoff)->delete();
        $this->line("- Deleted {$eventsCount} raw analytics events.");

        // Delete daily unique visitor records older than cutoff
        $visitorsCount = AnalyticsUniqueVisitor::where('date', '<', $cutoff->toDateString())->delete();
        $this->line("- Deleted {$visitorsCount} visitor deduplication records.");

        $this->info("Analytics cleanup complete. Aggregated daily metrics preserved.");

        return self::SUCCESS;
    }
}
