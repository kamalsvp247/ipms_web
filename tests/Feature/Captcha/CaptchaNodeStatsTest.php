<?php

use App\Models\CaptchaNode;
use App\Models\CaptchaNodeDailyStat;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
});

function statsFor(): array
{
    return test()->actingAs(User::factory()->create(['role' => 'super_admin']))
        ->getJson('/api/captcha-nodes')
        ->assertOk()
        ->json('stats');
}

it('reports solves for today, the last seven days and all time', function () {
    $node = CaptchaNode::factory()->create(['name' => 'solver-a']);

    CaptchaNodeDailyStat::create(['date' => Carbon::today(), 'captcha_node_id' => $node->id, 'solved' => 10, 'failed' => 2, 'total_ms' => 20_000]);
    CaptchaNodeDailyStat::create(['date' => Carbon::today()->subDays(3), 'captcha_node_id' => $node->id, 'solved' => 5, 'failed' => 0, 'total_ms' => 15_000]);

    // Outside the 7-day window: must count toward all time but not the week.
    CaptchaNodeDailyStat::create(['date' => Carbon::today()->subDays(30), 'captcha_node_id' => $node->id, 'solved' => 100, 'failed' => 8, 'total_ms' => 300_000]);

    $stats = statsFor();

    expect($stats['today']['solved'])->toBe(10);
    expect($stats['week']['solved'])->toBe(15);
    expect($stats['total']['solved'])->toBe(115);
    expect($stats['total']['failed'])->toBe(10);
});

it('derives success rate and average duration from solved work only', function () {
    $node = CaptchaNode::factory()->create();

    CaptchaNodeDailyStat::create(['date' => Carbon::today(), 'captcha_node_id' => $node->id, 'solved' => 3, 'failed' => 1, 'total_ms' => 9_000]);

    $stats = statsFor();

    expect($stats['today']['success_rate'])->toBe(75);
    // 9000ms over 3 solves — the failed attempt contributes no duration, so dividing by
    // attempts instead of solves would understate how long a real solve takes.
    expect($stats['today']['avg_ms'])->toBe(3_000);
});

it('reports no rate rather than zero percent when nothing ran', function () {
    $stats = statsFor();

    // A quiet day must not render as a 0% success rate, which reads as total failure.
    expect($stats['today']['solved'])->toBe(0);
    expect($stats['today']['success_rate'])->toBeNull();
    expect($stats['today']['avg_ms'])->toBeNull();
});

it('breaks the week down per node, busiest first', function () {
    $a = CaptchaNode::factory()->create(['name' => 'solver-a']);
    $b = CaptchaNode::factory()->create(['name' => 'solver-b']);

    CaptchaNodeDailyStat::create(['date' => Carbon::today(), 'captcha_node_id' => $a->id, 'solved' => 4, 'failed' => 0, 'total_ms' => 4_000]);
    CaptchaNodeDailyStat::create(['date' => Carbon::today(), 'captcha_node_id' => $b->id, 'solved' => 9, 'failed' => 3, 'total_ms' => 9_000]);

    $perNode = statsFor()['per_node'];

    expect($perNode)->toHaveCount(2);
    expect($perNode[0]['name'])->toBe('solver-b');
    expect($perNode[0]['solved'])->toBe(9);
    expect($perNode[1]['name'])->toBe('solver-a');
});

it('clears every solve total and tells the online nodes to zero their own counters', function () {
    $online = CaptchaNode::factory()->create(['solved' => 40, 'failed' => 3, 'avg_ms' => 2_500, 'last_error' => 'old failure']);
    $offline = CaptchaNode::factory()->offline()->create(['solved' => 12, 'failed' => 1, 'avg_ms' => 9_000]);
    $removed = CaptchaNode::factory()->create();

    CaptchaNodeDailyStat::create(['date' => Carbon::today(), 'captcha_node_id' => $online->id, 'solved' => 40, 'failed' => 3, 'total_ms' => 100_000]);
    CaptchaNodeDailyStat::create(['date' => Carbon::today()->subDays(20), 'captcha_node_id' => $removed->id, 'solved' => 7, 'failed' => 0, 'total_ms' => 7_000]);
    $removed->delete();

    test()->actingAs(User::factory()->create(['role' => 'super_admin']))
        ->deleteJson('/api/captcha-nodes/stats')
        ->assertOk()
        ->assertJson(['deleted_days' => 2, 'notified_nodes' => 1]);

    // Including the orphaned history of a node that was deleted — "start fresh" has to mean
    // the removed-node rows go too, or the console keeps showing work nobody owns.
    expect(CaptchaNodeDailyStat::count())->toBe(0);

    $stats = statsFor();
    expect($stats['total']['solved'])->toBe(0);
    expect($stats['per_node'])->toBe([]);

    // The node-reported columns are zeroed for every node, online or not; only the command
    // is limited to nodes that can actually receive it.
    expect($online->fresh()->solved)->toBe(0);
    expect($online->fresh()->last_error)->toBeNull();
    expect($offline->fresh()->solved)->toBe(0);

    // Without this the next heartbeat re-reports the node's process-lifetime numbers and
    // the totals reappear within ten seconds.
    expect($online->fresh()->pending_command)->toBe('reset_stats');
    expect($offline->fresh()->pending_command)->toBeNull();
});

it('survives a node being deleted after its work was counted', function () {
    $node = CaptchaNode::factory()->create();
    CaptchaNodeDailyStat::create(['date' => Carbon::today(), 'captcha_node_id' => $node->id, 'solved' => 7, 'failed' => 0, 'total_ms' => 7_000]);

    $node->delete();

    // History outlives the node: the FK nulls out rather than cascading the row away, so
    // a retired VPS cannot silently rewrite last week's totals.
    $stats = statsFor();

    expect($stats['total']['solved'])->toBe(7);
    expect($stats['per_node'][0]['name'])->toBe('removed node');
});
