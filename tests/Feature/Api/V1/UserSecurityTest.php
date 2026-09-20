<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Tests\TestCase;

class UserSecurityTest extends TestCase
{
    public function test_user_sensitive_attributes_are_hidden_from_serialization(): void
    {
        $user = new User([
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'password' => 'secret123',
            'role' => UserRole::User,
            'status' => UserStatus::Active,
        ]);
        $user->remember_token = 'secret-token';

        $serialized = $user->toArray();

        $this->assertArrayNotHasKey('password', $serialized);
        $this->assertArrayNotHasKey('remember_token', $serialized);
        $this->assertArrayHasKey('name', $serialized);
        $this->assertArrayHasKey('email', $serialized);
    }

    public function test_user_fillable_attributes_protect_against_mass_assignment(): void
    {
        $user = new User();
        $fillable = $user->getFillable();

        $this->assertContains('name', $fillable);
        $this->assertContains('email', $fillable);
        $this->assertContains('password', $fillable);
        $this->assertContains('role', $fillable);
        $this->assertContains('status', $fillable);

        // Sensitive columns must NOT be mass-assignable
        $this->assertNotContains('id', $fillable);
        $this->assertNotContains('remember_token', $fillable);
        $this->assertNotContains('email_verified_at', $fillable);
    }
}
