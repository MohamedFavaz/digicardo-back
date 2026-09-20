<?php

namespace Tests\Feature\Api\V1;

use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccountSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_deletion_fails_with_incorrect_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123!'),
        ]);

        $service = app(AccountSecurityService::class);
        $request = Request::create('/api/v1/account', 'DELETE', [
            'current_password' => 'WrongPassword!',
            'confirmation' => 'DELETE MY ACCOUNT',
        ]);
        $request->setLaravelSession(session()->driver());
        $request->session()->put('auth.recent_authenticated_at', now()->timestamp);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->deleteAccount($user, 'WrongPassword!', 'DELETE MY ACCOUNT', $request);
    }

    public function test_account_deletion_fails_with_invalid_confirmation_phrase(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123!'),
        ]);

        $service = app(AccountSecurityService::class);
        $request = Request::create('/api/v1/account', 'DELETE');
        $request->setLaravelSession(session()->driver());
        $request->session()->put('auth.recent_authenticated_at', now()->timestamp);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->deleteAccount($user, 'CorrectPassword123!', 'delete please', $request);
    }

    public function test_account_deletion_blocked_with_active_paid_subscription(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CorrectPassword123!'),
        ]);

        $plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro',
            'code' => 'pro',
            'price_cents' => 900,
            'is_active' => true,
            'features' => [],
            'limits' => [],
        ]);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'provider' => 'stripe',
            'provider_subscription_id' => 'sub_active_test',
            'status' => 'active',
            'billing_interval' => 'monthly',
            'cancel_at_period_end' => false,
        ]);

        $service = app(AccountSecurityService::class);
        $request = Request::create('/api/v1/account', 'DELETE');
        $request->setLaravelSession(session()->driver());
        $request->session()->put('auth.recent_authenticated_at', now()->timestamp);

        $this->expectException(\App\Exceptions\AccountDeletionBlockedException::class);
        $service->deleteAccount($user, 'CorrectPassword123!', 'DELETE MY ACCOUNT', $request);
    }

    public function test_account_deletion_succeeds_and_cascades_soft_deletes(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('MyPassword123!'),
        ]);

        $profile = Profile::create([
            'user_id' => $user->id,
            'username' => 'testuserdelete',
            'display_name' => 'Test User',
            'is_published' => true,
        ]);

        $service = app(AccountSecurityService::class);
        $request = Request::create('/api/v1/account', 'DELETE');
        $request->setLaravelSession(session()->driver());
        $request->session()->put('auth.recent_authenticated_at', now()->timestamp);

        $service->deleteAccount($user, 'MyPassword123!', 'DELETE MY ACCOUNT', $request);

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSoftDeleted('profiles', ['id' => $profile->id]);

        // Security events logged
        $this->assertDatabaseHas('account_security_events', [
            'user_id' => $user->id,
            'event_type' => 'account_deleted',
        ]);
    }
}
