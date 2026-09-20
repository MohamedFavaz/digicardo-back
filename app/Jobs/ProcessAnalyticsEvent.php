<?php

namespace App\Jobs;

use App\Models\AnalyticsEvent;
use App\Services\AnalyticsAggregationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ProcessAnalyticsEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param array{profile_id: string, event_type: string, block_id?: ?string, referrer_host?: ?string, metadata?: ?array<string, mixed>, occurred_at?: ?string} $eventData
     * @param string $visitorHash
     */
    public function __construct(
        public readonly array $eventData,
        public readonly string $visitorHash
    ) {}

    /**
     * Execute the job.
     */
    public function handle(AnalyticsAggregationService $aggregationService): void
    {
        $occurredAt = !empty($this->eventData['occurred_at'])
            ? Carbon::parse($this->eventData['occurred_at'])
            : Carbon::now();

        // Store raw event
        $event = AnalyticsEvent::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $this->eventData['profile_id'],
            'block_id' => $this->eventData['block_id'] ?? null,
            'event_type' => $this->eventData['event_type'],
            'visitor_hash' => $this->visitorHash,
            'referrer_host' => $this->eventData['referrer_host'] ?? null,
            'metadata' => $this->eventData['metadata'] ?? null,
            'occurred_at' => $occurredAt,
        ]);

        // Aggregate into daily metric rollup
        $aggregationService->aggregateEvent($event, $this->visitorHash);
    }
}
