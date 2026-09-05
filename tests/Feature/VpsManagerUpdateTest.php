<?php

use App\Jobs\UpdateVpsBotJob;
use App\Models\AgentSlot;
use App\Models\Setting;
use App\Models\User;
use App\Models\VpsInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'super_admin']);
    Setting::instance()->update(['latest_jar_version' => 'duronto_v_9.9']);
});

function vpsSlot(string $ip, string $botVersion = 'duronto_v_1.0'): AgentSlot
{
    return AgentSlot::create([
        'name' => 'slot-'.$ip,
        'api_key' => 'key-'.str_replace('.', '', $ip),
        'status' => 'online',
        'ip_address' => $ip,
        'bot_version' => $botVersion,
    ]);
}

function makeInstance(string $ip, ?int $slotId = null): VpsInstance
{
    return VpsInstance::create([
        'agent_slot_id' => $slotId,
        'provider' => 'lightnode',
        'instance_name' => 'vps-'.$ip,
        'public_ip' => $ip,
        'ssh_username' => 'root',
        'root_password' => 'secret-pass',
        'status' => 'online',
    ]);
}

it('marks the instance updating and dispatches the update job', function () {
    Queue::fake();
    $slot = vpsSlot('10.0.0.1');
    $instance = makeInstance('10.0.0.1', $slot->id);

    $this->actingAs($this->admin)
        ->postJson("/api/vps/instances/{$instance->id}/update")
        ->assertSuccessful()
        ->assertJson(['queued' => true]);

    expect($instance->fresh()->update_status)->toBe('updating');
    Queue::assertPushed(UpdateVpsBotJob::class);
});

it('does not dispatch when the instance has no public IP', function () {
    Queue::fake();
    $instance = makeInstance('10.0.0.2');
    $instance->update(['public_ip' => null]);

    $this->actingAs($this->admin)
        ->postJson("/api/vps/instances/{$instance->id}/update")
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

it('does not dispatch when no matched worker exists', function () {
    Queue::fake();
    $instance = makeInstance('10.0.0.3');

    $this->actingAs($this->admin)
        ->postJson("/api/vps/instances/{$instance->id}/update")
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

it('update-all queues jobs only for outdated online workers', function () {
    Queue::fake();

    $outdatedSlot = vpsSlot('10.0.0.10', 'duronto_v_1.0');
    $outdated = makeInstance('10.0.0.10', $outdatedSlot->id);

    $currentSlot = vpsSlot('10.0.0.11', 'duronto_v_9.9');
    makeInstance('10.0.0.11', $currentSlot->id);

    $this->actingAs($this->admin)
        ->postJson('/api/vps/instances/update-all')
        ->assertSuccessful()
        ->assertJson(['queued' => 1]);

    expect($outdated->fresh()->update_status)->toBe('updating');
    Queue::assertPushed(UpdateVpsBotJob::class, 1);
});
