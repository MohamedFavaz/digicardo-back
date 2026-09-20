<?php

namespace App\Console\Commands;

use App\Models\ProfileBlock;
use App\Models\ProfileMedia;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupOrphanMedia extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:cleanup-orphans {--hours=24 : Grace period in hours before cleaning up deleted media}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely clean up soft-deleted and unreferenced media files and metadata';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = Carbon::now()->subHours($hours);

        $this->info("Scanning for orphan media deleted before {$cutoff->toIso8601String()} (grace period: {$hours}h)...");

        // 1. Process soft-deleted media records older than cutoff
        $deletedMedia = ProfileMedia::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->get();

        $deletedCount = 0;
        foreach ($deletedMedia as $media) {
            try {
                if (Storage::disk($media->disk)->exists($media->path)) {
                    Storage::disk($media->disk)->delete($media->path);
                }
                $media->forceDelete();
                $deletedCount++;
            } catch (\Throwable $e) {
                Log::error("Failed to clean up orphan media ID {$media->id}: " . $e->getMessage());
                $this->warn("Failed to clean up media ID {$media->id}");
            }
        }

        $this->info("Cleaned up {$deletedCount} soft-deleted orphan media records.");
        Log::info("media:cleanup-orphans: Cleaned up {$deletedCount} records.");

        return Command::SUCCESS;
    }
}
