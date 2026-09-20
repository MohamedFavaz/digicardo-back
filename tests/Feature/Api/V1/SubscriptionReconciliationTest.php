<?php

namespace Tests\Feature\Api\V1;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_reconciliation_command_runs_successfully(): void
    {
        $user = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->first();

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'provider' => 'null',
            'provider_subscription_id' => 'sub_reconcile_123',
            'status' => SubscriptionStatus::Active->value,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->artisan('subscriptions:reconcile')
            ->expectsOutputToContain('Reconciliation complete.')
            ->assertExitCode(0);
    }

    public function test_reconciliation_expires_past_grace_period(): void
    {
        $user = User::factory()->create();
        $proPlan = Plan::where('code', 'pro')->first();

        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $proPlan->id,
            'provider' => 'null',
            'provider_subscription_id' => 'sub_past_grace',
            'status' => SubscriptionStatus::PastDue->value,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDays(10),
            'grace_period_ends_at' => now()->subDay(),
        ]);

        $this->artisan('subscriptions:reconcile')
            ->assertExitCode(0);

        $sub->refresh();
        $this->assertEquals(SubscriptionStatus::Expired, $sub->status);
    }
}
