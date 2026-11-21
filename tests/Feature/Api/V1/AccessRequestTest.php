<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AccessRequest;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Request access" (public sign-up when self-registration is off). */
class AccessRequestTest extends TestCase
{
    use RefreshDatabase;

    private function submit(array $overrides = [])
    {
        return $this->postJson('/api/v1/access-requests', array_merge([
            'name' => 'Asha Menon',
            'email' => 'asha@example.test',
            'mobile' => '+91 98765 43210',
            'desired_username' => 'ashamenon',
        ], $overrides));
    }

    public function test_a_new_person_can_request_access(): void
    {
        $this->submit()->assertSuccessful();
        $this->assertTrue(AccessRequest::where('email', 'asha@example.test')->exists());
    }

    public function test_emails_that_already_have_an_account_are_refused(): void
    {
        foreach ([UserRole::User, UserRole::Agent, UserRole::Admin] as $i => $role) {
            User::factory()->create(['role' => $role, 'status' => UserStatus::Active, 'email' => "taken{$i}@example.test"]);
            // Case and spaces don't get around it.
            $this->submit(['email' => "  TAKEN{$i}@Example.test ", 'desired_username' => "free{$i}"])
                ->assertStatus(422)
                ->assertJsonPath('error.details.email.0', 'This email already has a Digicardo account. Please sign in instead, or use a different email.');
        }
        $this->assertSame(0, AccessRequest::count());
    }

    public function test_taken_reserved_or_invalid_handles_are_refused(): void
    {
        $owner = User::factory()->create(['role' => UserRole::User, 'status' => UserStatus::Active]);
        Profile::create(['user_id' => $owner->id, 'username' => 'brightline', 'is_public' => true, 'version' => 1]);

        $this->submit(['desired_username' => '@Brightline'])->assertStatus(422)->assertJsonValidationErrors('desired_username', 'error.details');
        $this->submit(['desired_username' => 'login'])->assertStatus(422);
        $this->submit(['desired_username' => 'my shop'])->assertStatus(422);
        $this->assertSame(0, AccessRequest::count());

        // No handle at all is fine.
        $this->submit(['desired_username' => null])->assertSuccessful();
    }
}
