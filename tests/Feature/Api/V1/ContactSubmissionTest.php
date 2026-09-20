<?php

namespace Tests\Feature\Api\V1;

use App\Models\ContactSubmission;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'username' => 'contactstar',
            'display_name' => 'Contact Star',
            'is_public' => true,
            'version' => 1,
        ]);
    }

    public function test_public_user_can_submit_contact_form(): void
    {
        $response = $this->postJson(route('api.v1.public.contact.submit', ['username' => 'contactstar']), [
            'name' => 'Alice Visitor',
            'email' => 'alice@example.com',
            'phone' => '+15551234567',
            'message' => 'Hello, I loved your profile!',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('contact_submissions', [
            'profile_id' => $this->profile->id,
            'name' => 'Alice Visitor',
            'email' => 'alice@example.com',
            'phone' => '+15551234567',
            'message' => 'Hello, I loved your profile!',
        ]);
    }

    public function test_honeypot_spam_is_rejected(): void
    {
        $response = $this->postJson(route('api.v1.public.contact.submit', ['username' => 'contactstar']), [
            'name' => 'Spam Bot',
            'email' => 'bot@spammer.com',
            'message' => 'Buy our fake products',
            'website_hp' => 'http://spam-link.com', // Filled honeypot
        ]);

        $response->assertStatus(422);
    }

    public function test_non_existent_profile_returns_404(): void
    {
        $response = $this->postJson(route('api.v1.public.contact.submit', ['username' => 'nonexistent']), [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'message' => 'Hello',
        ]);

        $response->assertStatus(404);
    }

    public function test_owner_can_list_and_delete_submissions(): void
    {
        $sub = ContactSubmission::create([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'profile_id' => $this->profile->id,
            'name' => 'Bob Client',
            'email' => 'bob@example.com',
            'message' => 'Interested in your services.',
        ]);

        $listRes = $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.v1.profile.contact.index'));

        $listRes->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'message',
                        'created_at',
                    ],
                ],
            ]);

        $delRes = $this->actingAs($this->user, 'sanctum')
            ->deleteJson(route('api.v1.profile.contact.destroy', ['submission' => $sub->id]));

        $delRes->assertStatus(200);
        $this->assertSoftDeleted('contact_submissions', ['id' => $sub->id]);
    }

    public function test_foreign_user_cannot_delete_contact_submission(): void
    {
        $user2 = User::factory()->create();
        $sub = ContactSubmission::create([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'profile_id' => $this->profile->id,
            'name' => 'Bob Client',
            'email' => 'bob@example.com',
            'message' => 'Interested in your services.',
        ]);

        $response = $this->actingAs($user2, 'sanctum')
            ->deleteJson(route('api.v1.profile.contact.destroy', ['submission' => $sub->id]));

        $response->assertStatus(403);
    }
}
