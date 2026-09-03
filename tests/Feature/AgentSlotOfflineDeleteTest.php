<?php

use App\Models\Account;
use App\Models\AgentSlot;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeAgentSlot(string $status): AgentSlot
{
    return AgentSlot::create([
        'name' => 'slot-'.uniqid(),
        'api_key' => 'key-'.uniqid(),
        'status' => $status,
    ]);
}

it('deletes only offline workers and unassigns their accounts', function () {
    $user = User::factory()->create(['role' => 'super_admin']);

    $offlineSlot = makeAgentSlot('offline');
    $onlineSlot = makeAgentSlot('online');

    $unassignedAccount = Account::factory()->for($user)->create([
        'agent_slot_id' => $offlineSlot->id,
    ]);
    $keptAccount = Account::factory()->for($user)->create([
        'agent_slot_id' => $onlineSlot->id,
    ]);

    $response = actingAs($user, 'sanctum')->deleteJson('/api/agent-slots/offline');

    $response->assertOk()->assertJson([
        'deleted' => 1,
        'accounts_unassigned' => 1,
    ]);

    expect(AgentSlot::find($offlineSlot->id))->toBeNull();
    expect(AgentSlot::find($onlineSlot->id))->not->toBeNull();
    expect($unassignedAccount->fresh()->agent_slot_id)->toBeNull();
    expect($keptAccount->fresh()->agent_slot_id)->toBe($onlineSlot->id);
});

it('is a no-op when there are no offline workers', function () {
    $user = User::factory()->create(['role' => 'super_admin']);
    makeAgentSlot('online');

    $response = actingAs($user, 'sanctum')->deleteJson('/api/agent-slots/offline');

    $response->assertOk()->assertJson([
        'deleted' => 0,
        'accounts_unassigned' => 0,
    ]);
});

it('forbids non-admins from deleting offline workers', function () {
    $user = User::factory()->create(['role' => 'agent']);
    makeAgentSlot('offline');

    actingAs($user, 'sanctum')->deleteJson('/api/agent-slots/offline')->assertForbidden();
});
