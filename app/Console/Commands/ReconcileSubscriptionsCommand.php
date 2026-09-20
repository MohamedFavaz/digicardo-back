<?php

namespace App\Console\Commands;

use App\Contracts\SubscriptionProviderInterface;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileSubscriptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:reconcile {--limit=100 : Maximum subscriptions to reconcile}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile local subscription records with the upstream payment provider';

    /**
     * Execute the console command.
     */
    public function handle(
        SubscriptionProviderInterface $provider,
        SubscriptionSyncService $syncService
    ): int {
        $this->info('Starting subscription reconciliation...');

        $limit = (int) $this->option('limit');

        $subscriptions = Subscription::whereNotNull('provider_subscription_id')
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::GracePeriod->value,
            ])
            ->take($limit)
            ->get();

        $this->info("Found {$subscriptions->count()} subscriptions to reconcile.");

        $reconciled = 0;
        $failed = 0;

        foreach ($subscriptions as $sub) {
            try {
                // 1. Check expired grace periods
                if ($sub->grace_period_ends_at && $sub->grace_period_ends_at->isPast()) {
                    $sub->update([
                        'status' => SubscriptionStatus::Expired,
                        'grace_period_ends_at' => null,
                    ]);
                    $this->line("Subscription [{$sub->id}] grace period expired, marked as expired.");
                    $reconciled++;
                    continue;
                }

                // 2. Query upstream provider
                $providerData = $provider->retrieveSubscription($sub->provider_subscription_id);

                if ($providerData) {
                    $syncService->syncFromProviderData($providerData, $sub->user);
                    $reconciled++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Failed to reconcile subscription [{$sub->id}]: {$e->getMessage()}");
                Log::error('[ReconciliationCommand] Error reconciling subscription', [
                    'subscription_id' => $sub->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reconciliation complete. Reconciled: {$reconciled}, Failed: {$failed}.");

        return 0;
    }
}
