<?php

use App\Models\User;
use App\Services\BotControl\ProcessBotController;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'super_admin']);
    $this->user = User::factory()->create(['role' => 'user']);
});

it('returns success with pid when bot starts successfully', function () {
    $mock = $this->mock(ProcessBotController::class);
    $mock->shouldReceive('start')->once()->andReturn([
        'success' => true,
        'message' => 'Bot started successfully (PID 1234).',
        'pid' => 1234,
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/bot/start')
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('pid', 1234);
});

it('returns failure with log content when bot exits immediately', function () {
    $mock = $this->mock(ProcessBotController::class);
    $mock->shouldReceive('start')->once()->andReturn([
        'success' => false,
        'message' => 'Bot process exited immediately.',
        'log' => '[2026-02-17 12:00:00] Bot starting...\n[ERROR] Some maven error',
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/bot/start')
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Bot process exited immediately.')
        ->assertJsonStructure(['log']);
});

it('returns failure with log when bot fails to start', function () {
    $mock = $this->mock(ProcessBotController::class);
    $mock->shouldReceive('start')->once()->andReturn([
        'success' => false,
        'message' => 'Failed to start bot.',
        'log' => '',
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/bot/start')
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Failed to start bot.');
});

it('denies bot start for non-admin users', function () {
    $this->actingAs($this->user)
        ->postJson('/api/bot/start')
        ->assertForbidden();
});

it('denies bot start for guests', function () {
    $this->postJson('/api/bot/start')
        ->assertUnauthorized();
});
