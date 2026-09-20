<?php

namespace App\Jobs;

use App\Contracts\EmailProviderInterface;
use App\Models\EmailDelivery;
use App\Services\Email\EmailDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * Exponential backoff delays in seconds (0s, 30s, 2m, 10m, 30m).
     *
     * @var array<int, int>
     */
    public array $backoff = [0, 30, 120, 600, 1800];

    public string $deliveryId;
    public string $htmlBody;
    public ?string $textBody;
    public array $headers;

    /**
     * Create a new job instance.
     *
     * @param string $deliveryId
     * @param string $htmlBody
     * @param string|null $textBody
     * @param array<string, string> $headers
     */
    public function __construct(
        string $deliveryId,
        string $htmlBody,
        ?string $textBody = null,
        array $headers = []
    ) {
        $this->deliveryId = $deliveryId;
        $this->htmlBody = $htmlBody;
        $this->textBody = $textBody;
        $this->headers = $headers;
    }

    /**
     * Execute the job.
     */
    public function handle(EmailProviderInterface $provider, EmailDeliveryService $deliveryService): void
    {
        $delivery = EmailDelivery::find($this->deliveryId);
        if (!$delivery) {
            Log::error("[SendEmailJob] Delivery record not found: {$this->deliveryId}");
            return;
        }

        $deliveryService->markSending($delivery);

        try {
            $messageId = $provider->send(
                $delivery->recipient,
                $delivery->subject,
                $this->htmlBody,
                $this->textBody,
                $this->headers
            );

            $deliveryService->markSent($delivery, $messageId);
        } catch (Throwable $e) {
            $deliveryService->recordFailure($delivery, $e->getMessage(), false);
            throw $e;
        }
    }

    /**
     * Handle final failure after exhausting all attempts.
     */
    public function failed(?Throwable $exception): void
    {
        $delivery = EmailDelivery::find($this->deliveryId);
        if ($delivery) {
            app(EmailDeliveryService::class)->recordFailure(
                $delivery,
                $exception ? $exception->getMessage() : 'Max retries exhausted',
                true
            );
        }

        Log::error("[SendEmailJob] Email permanently failed for delivery: {$this->deliveryId}", [
            'error' => $exception ? $exception->getMessage() : 'Unknown error',
        ]);
    }
}
