<?php

use App\Models\Account;
use App\Models\AgentSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('factory-created account inherits DB default of 4', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->for($user)->create()->fresh();

    expect($account->bypass_slot_parallel_shots)->toBe(4);
});

it('persists bypass_slot_parallel_shots from store endpoint', function () {
    $user = User::factory()->create(['role' => 'super_admin']);

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/accounts', [
        'phone' => '01700000000',
        'password' => 'secret',
        'bypass_slot_parallel_shots' => 7,
    ]);

    $response->assertSuccessful();
    expect(Account::where('phone', '01700000000')->first()->bypass_slot_parallel_shots)->toBe(7);
});

it('rejects bypass_slot_parallel_shots below 1', function () {
    $user = User::factory()->create(['role' => 'super_admin']);

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/accounts', [
        'phone' => '01700000001',
        'password' => 'secret',
        'bypass_slot_parallel_shots' => 0,
    ]);

    $response->assertStatus(422);
});

it('accepts null bypass_slot_parallel_shots (stored as null, normalized to 4 in API output)', function () {
    $user = User::factory()->create(['role' => 'super_admin']);

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/accounts', [
        'phone' => '01700000002',
        'password' => 'secret',
        'bypass_slot_parallel_shots' => null,
    ]);

    $response->assertSuccessful();
    expect(Account::where('phone', '01700000002')->first()->bypass_slot_parallel_shots)->toBeNull();
});

it('omitting bypass_slot_parallel_shots on store applies DB default 4', function () {
    $user = User::factory()->create(['role' => 'super_admin']);

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/accounts', [
        'phone' => '01700000003',
        'password' => 'secret',
    ]);

    $response->assertSuccessful();
    expect(Account::where('phone', '01700000003')->first()->bypass_slot_parallel_shots)->toBe(4);
});

it('updates bypass_slot_parallel_shots via update endpoint', function () {
    $user = User::factory()->create(['role' => 'super_admin']);
    $account = Account::factory()->for($user)->create();

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/accounts/{$account->id}", [
        'bypass_slot_parallel_shots' => 9,
    ]);

    $response->assertSuccessful();
    expect($account->fresh()->bypass_slot_parallel_shots)->toBe(9);
});

it('exposes bypassSlotParallelShots in /api/config slot-scoped response', function () {
    $slot = AgentSlot::create([
        'name' => 'test-slot',
        'api_key' => 'test-key-'.uniqid(),
        'status' => 'online',
    ]);

    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'is_active' => true,
        'status' => 'running',
        'bypass_slot_parallel_shots' => 6,
    ]);

    $response = $this->getJson('/api/config', [
        'Authorization' => 'Bearer '.$slot->api_key,
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('accounts.0.bypassSlotParallelShots', 6);
    $response->assertJsonPath('accounts.0.phone', $account->phone);
});

it('persists otp_verify_burst_delay_ms via update endpoint', function () {
    $user = User::factory()->create(['role' => 'super_admin']);
    $account = Account::factory()->for($user)->create();

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/accounts/{$account->id}", [
        'otp_verify_burst_delay_ms' => 500,
    ]);

    $response->assertSuccessful();
    expect($account->fresh()->otp_verify_burst_delay_ms)->toBe(500);
});

it('rejects otp_verify_burst_delay_ms below 100', function () {
    $user = User::factory()->create(['role' => 'super_admin']);
    $account = Account::factory()->for($user)->create();

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/accounts/{$account->id}", [
        'otp_verify_burst_delay_ms' => 50,
    ]);

    $response->assertStatus(422);
});

it('exposes otpVerifyBurstDelayMs in /api/config and falls back to 1000 when null', function () {
    $slot = AgentSlot::create([
        'name' => 'test-slot-burst',
        'api_key' => 'test-key-'.uniqid(),
        'status' => 'online',
    ]);

    $user = User::factory()->create(['role' => 'user']);
    Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'is_active' => true,
        'status' => 'running',
        'otp_verify_burst_delay_ms' => null,
    ]);

    $response = $this->getJson('/api/config', [
        'Authorization' => 'Bearer '.$slot->api_key,
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('accounts.0.otpVerifyBurstDelayMs', 1000);
});

it('falls back to 4 in /api/config when bypass_slot_parallel_shots is null on the row', function () {
    $slot = AgentSlot::create([
        'name' => 'test-slot-null',
        'api_key' => 'test-key-'.uniqid(),
        'status' => 'online',
    ]);

    $user = User::factory()->create(['role' => 'user']);
    Account::factory()->for($user)->create([
        'agent_slot_id' => $slot->id,
        'is_active' => true,
        'status' => 'running',
        'bypass_slot_parallel_shots' => null,
    ]);

    $response = $this->getJson('/api/config', [
        'Authorization' => 'Bearer '.$slot->api_key,
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('accounts.0.bypassSlotParallelShots', 4);
});
