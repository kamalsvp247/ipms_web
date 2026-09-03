<?php

namespace Tests\Feature;

use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\GmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_gmail_index_returns_not_connected_when_no_account(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/gmail/messages');

        $response->assertStatus(200)
            ->assertJson(['connected' => false]);
    }

    public function test_gmail_index_returns_messages_when_connected(): void
    {
        $user = User::factory()->create();
        $account = GoogleAccount::create([
            'user_id' => $user->id,
            'email_address' => 'test@gmail.com',
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/gmail/messages');

        $response->assertStatus(200)
            ->assertJson([
                'connected' => true,
                'email' => 'test@gmail.com',
            ])
            ->assertJsonStructure(['messages' => ['data', 'current_page', 'last_page', 'total']]);
    }

    public function test_batch_trash_requires_ids(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/gmail/messages/batch-trash', [])
            ->assertStatus(422);
    }

    public function test_batch_trash_returns_403_when_not_connected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/gmail/messages/batch-trash', ['ids' => ['abc123']])
            ->assertStatus(403);
    }

    public function test_clear_all_requires_permanent_boolean(): void
    {
        $user = User::factory()->create();
        GoogleAccount::create([
            'user_id' => $user->id,
            'email_address' => 'test@gmail.com',
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'expires_at' => now()->addHour(),
        ]);

        $gmailServiceMock = $this->mock(GmailService::class);
        $gmailServiceMock->shouldReceive('refreshIfNeeded')->andReturn(null);
        $gmailServiceMock->shouldReceive('listAllInboxIds')->andReturn(['messages' => [], 'nextPageToken' => null]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/gmail/messages/clear-all', ['permanent' => 'not-a-bool'])
            ->assertStatus(422);
    }

    public function test_gmail_page_renders(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/gmail')->assertStatus(200);
    }

    public function test_webhook_returns_200_for_empty_payload(): void
    {
        $this->postJson('/api/webhooks/gmail', [])->assertStatus(200);
    }
}
