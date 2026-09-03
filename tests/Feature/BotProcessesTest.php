<?php

use App\Models\User;
use App\Services\BotControl\ProcessBotController;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'super_admin']);
    $this->user = User::factory()->create(['role' => 'user']);
});

it('returns processes list for authorized users', function () {
    $mock = $this->mock(ProcessBotController::class);
    $mock->shouldReceive('listProcesses')->once()->andReturn([
        'processes' => [
            ['pid' => 1234, 'command' => 'java -jar bot.jar', 'memory' => '128.5 MB'],
            ['pid' => 5678, 'command' => 'java -jar worker.jar', 'memory' => '64.2 MB'],
        ],
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/bot/processes')
        ->assertSuccessful()
        ->assertJsonCount(2, 'processes')
        ->assertJsonPath('processes.0.pid', 1234)
        ->assertJsonPath('processes.1.pid', 5678);
});

it('returns empty processes list when none running', function () {
    $mock = $this->mock(ProcessBotController::class);
    $mock->shouldReceive('listProcesses')->once()->andReturn(['processes' => []]);

    $this->actingAs($this->admin)
        ->getJson('/api/bot/processes')
        ->assertSuccessful()
        ->assertJsonCount(0, 'processes');
});

it('denies processes list for non-admin users', function () {
    $this->actingAs($this->user)
        ->getJson('/api/bot/processes')
        ->assertForbidden();
});

it('denies processes list for guests', function () {
    $this->getJson('/api/bot/processes')
        ->assertUnauthorized();
});

it('kills a specific process for authorized users', function () {
    $mock = $this->mock(ProcessBotController::class);
    $mock->shouldReceive('killProcess')->with(1234)->once()->andReturn([
        'success' => true,
        'message' => 'Process 1234 killed.',
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/bot/processes/1234/kill')
        ->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Process 1234 killed.');
});

it('returns error when killing non-running process', function () {
    $mock = $this->mock(ProcessBotController::class);
    $mock->shouldReceive('killProcess')->with(9999)->once()->andReturn([
        'success' => false,
        'message' => 'Process 9999 is not running.',
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/bot/processes/9999/kill')
        ->assertUnprocessable()
        ->assertJsonPath('success', false);
});

it('denies kill process for non-admin users', function () {
    $this->actingAs($this->user)
        ->postJson('/api/bot/processes/1234/kill')
        ->assertForbidden();
});

it('denies kill process for guests', function () {
    $this->postJson('/api/bot/processes/1234/kill')
        ->assertUnauthorized();
});
