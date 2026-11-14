<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CardCategory;
use App\Enums\CardStatus;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_their_cards(): void
    {
        $user = User::factory()->create();

        Profile::create([
            'user_id' => $user->id,
            'username' => 'card-one',
            'card_title' => 'First Card',
            'category' => CardCategory::Business,
            'status' => CardStatus::Published,
            'is_primary' => true,
            'template_id' => 'vcard',
            'version' => 1,
        ]);

        Profile::create([
            'user_id' => $user->id,
            'username' => 'card-two',
            'card_title' => 'Second Card',
            'category' => CardCategory::Personal,
            'status' => CardStatus::Draft,
            'is_primary' => false,
            'template_id' => 'botanical',
            'version' => 1,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson(route('api.v1.cards.index'));

        $response->assertStatus(200);
        $data = $response->json('data') ?? $response->json();
        $this->assertCount(2, $data);
    }

    public function test_user_can_create_a_card(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.cards.store'), [
                'category' => 'business',
                'template_id' => 'vcard',
                'card_title' => 'My First Business Card',
                'display_name' => 'John Doe',
                'bio' => 'CEO & Founder',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'card_title' => 'My First Business Card',
            'template_id' => 'vcard',
        ]);
    }

    public function test_user_can_duplicate_a_card(): void
    {
        $user = User::factory()->create();

        $card = Profile::create([
            'user_id' => $user->id,
            'username' => 'original-card',
            'card_title' => 'Original Card',
            'category' => CardCategory::Portfolio,
            'status' => CardStatus::Published,
            'is_primary' => true,
            'template_id' => 'vcard',
            'version' => 1,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.cards.duplicate', ['card' => $card->id]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'card_title' => 'Original Card (Copy)',
            'status' => 'draft',
            'is_primary' => 0,
        ]);
    }

    public function test_user_can_publish_and_unpublish_a_card(): void
    {
        $user = User::factory()->create();

        $card = Profile::create([
            'user_id' => $user->id,
            'username' => 'draft-card',
            'card_title' => 'Draft Card',
            'category' => CardCategory::Business,
            'status' => CardStatus::Draft,
            'is_primary' => false,
            'template_id' => 'vcard',
            'version' => 1,
        ]);

        // Publish
        $publishResponse = $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.cards.publish', ['card' => $card->id]));

        $publishResponse->assertStatus(200);
        $this->assertEquals('published', $card->fresh()->status->value);
        $this->assertTrue($card->fresh()->is_public);

        // Unpublish
        $unpublishResponse = $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.cards.unpublish', ['card' => $card->id]));

        $unpublishResponse->assertStatus(200);
        $this->assertEquals('draft', $card->fresh()->status->value);
        $this->assertFalse($card->fresh()->is_public);
    }

    public function test_user_can_set_primary_card(): void
    {
        $user = User::factory()->create();

        $cardOne = Profile::create([
            'user_id' => $user->id,
            'username' => 'card-1',
            'card_title' => 'Card 1',
            'category' => CardCategory::Business,
            'status' => CardStatus::Published,
            'is_primary' => true,
            'template_id' => 'vcard',
            'version' => 1,
        ]);

        $cardTwo = Profile::create([
            'user_id' => $user->id,
            'username' => 'card-2',
            'card_title' => 'Card 2',
            'category' => CardCategory::Personal,
            'status' => CardStatus::Published,
            'is_primary' => false,
            'template_id' => 'botanical',
            'version' => 1,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(route('api.v1.cards.set-primary', ['card' => $cardTwo->id]));

        $response->assertStatus(200);
        $this->assertFalse($cardOne->fresh()->is_primary);
        $this->assertTrue($cardTwo->fresh()->is_primary);
    }
}
