<?php

use App\Models\AgentSlot;
use App\Models\BotLog;

beforeEach(function () {
    $this->slot = AgentSlot::create([
        'name' => 'Batch Size Slot',
        'api_key' => 'test-slot-key-'.uniqid(),
    ]);
});

/**
 * @return array<int, array<string, mixed>>
 */
function batchOfLogs(int $count): array
{
    return array_map(fn (int $i): array => [
        'account_phone' => '017000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
        'method' => 'POST',
        'url' => 'https://api.ivacbd.com/iams/api/v1/slots/reserve-slot',
        'status_code' => 200,
        'logged_at' => now()->toDateTimeString(),
    ], range(1, $count));
}

function postBatch(string $apiKey, int $count)
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$apiKey])
        ->postJson('/api/slots/logs', ['logs' => batchOfLogs($count)]);
}

it('accepts a full 250-entry batch from the bot log shipper', function () {
    postBatch($this->slot->api_key, 250)->assertOk();

    expect(BotLog::where('agent_slot_id', $this->slot->id)->count())->toBe(250);
});

it('still accepts the legacy 100-entry batch size from older bot builds', function () {
    postBatch($this->slot->api_key, 100)->assertOk();

    expect(BotLog::where('agent_slot_id', $this->slot->id)->count())->toBe(100);
});

it('rejects a batch larger than the shipper will ever send', function () {
    postBatch($this->slot->api_key, 251)->assertStatus(422);

    expect(BotLog::where('agent_slot_id', $this->slot->id)->count())->toBe(0);
});
