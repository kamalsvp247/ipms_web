<?php

use App\Services\Captcha\ExtractorRepairService;
use App\Services\CaptchaAlgorithmService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * The repair service hands a live production extractor to an autonomous agent, so the
 * value is entirely in the guards: what stops a run, and what rejects its output. Those
 * are what these cover. The agent invocation itself is faked — this asserts the decision
 * logic around it, not Claude's ability to fix a parser.
 */
beforeEach(function () {
    Cache::flush();

    $this->bundle = storage_path('app/captcha/corpus/'.\Illuminate\Support\Str::random(8).'.js');
    @mkdir(dirname($this->bundle), 0775, true);
    file_put_contents($this->bundle, '// fake bundle');

    $this->service = new ExtractorRepairService(app(CaptchaAlgorithmService::class));
});

afterEach(function () {
    @unlink($this->bundle);
});

it('refuses to start when the working tree already has uncommitted extractor edits', function () {
    // A human mid-edit must never be overwritten: the snapshot/restore cycle would
    // silently discard their work-in-progress.
    Process::fake([
        '*git*status*' => Process::result(" M app/Scripts/analyze_captcha_algo.py\n"),
        '*' => Process::result(''),
    ]);

    $outcome = $this->service->attempt($this->bundle, str_repeat('a', 64), 'structural');

    expect($outcome['repaired'])->toBeFalse();
    expect($outcome['stage'])->toBe('preflight');
    expect($outcome['detail'])->toContain('analyze_captcha_algo.py');
});

it('refuses to start when the failing bundle is not on disk', function () {
    Process::fake(['*' => Process::result('')]);

    $outcome = $this->service->attempt('/nonexistent/bundle.js', str_repeat('b', 64), 'structural');

    expect($outcome['repaired'])->toBeFalse();
    expect($outcome['stage'])->toBe('preflight');
});

it('stops attempting after the cap so a hopeless bundle cannot burn tokens forever', function () {
    Process::fake(['*' => Process::result('')]);

    $hash = str_repeat('c', 64);
    Cache::put(ExtractorRepairService::ATTEMPT_CACHE_PREFIX.substr($hash, 0, 16), 2, now()->addDay());

    $outcome = $this->service->attempt($this->bundle, $hash, 'structural');

    expect($outcome['repaired'])->toBeFalse();
    expect($outcome['stage'])->toBe('exhausted');
});

it('aborts before running the agent when the corpus is already red', function () {
    // A pre-existing regression means an agent edit would be layered on top of an unknown
    // breakage, and the gate afterwards could not attribute the failure.
    Process::fake([
        '*git*' => Process::result(''),
        '*artisan*' => Process::result('Tests: 3 failed, 11 passed', '', 1),
        '*' => Process::result(''),
    ]);

    $outcome = $this->service->attempt($this->bundle, str_repeat('d', 64), 'structural');

    expect($outcome['repaired'])->toBeFalse();
    expect($outcome['stage'])->toBe('baseline');

    Process::assertNotRan(fn ($process) => str_contains($process->command[0] ?? '', 'claude'));
});

it('treats a corpus that passes with skips as a failure', function () {
    // "Green with N skipped" is exactly how the original corpus decayed to no coverage
    // while still reporting success — it must never satisfy the gate.
    Process::fake([
        '*git*' => Process::result(''),
        '*artisan*' => Process::result('Tests: 6 skipped, 8 passed (120 assertions)'),
        '*' => Process::result(''),
    ]);

    $outcome = $this->service->attempt($this->bundle, str_repeat('e', 64), 'structural');

    expect($outcome['repaired'])->toBeFalse();
    expect($outcome['stage'])->toBe('baseline');
    expect($outcome['detail'])->toContain('skipped');
});

it('ignores files another session changed mid-run when scoping the agent diff', function () {
    // This checkout is shared: a concurrent session editing an unrelated file must not be
    // attributed to the agent. A real smoke test failed exactly this way when another
    // session edited the payment driver while a repair was running.
    $baselineDirty = " M app/Scripts/dgepay_payment_driver.cjs\n";

    Process::fake([
        '*git*status*' => Process::result($baselineDirty),
        '*artisan*' => Process::result('Tests: 15 passed (253 assertions)'),
        '*claude*' => Process::result(json_encode(['is_error' => false, 'session_id' => 'x', 'result' => 'done'])),
        '*' => Process::result(''),
    ]);

    $outcome = $this->service->attempt($this->bundle, str_repeat('9', 64), 'structural');

    // The unrelated file is dirty both before and after, so it is not "changed during the
    // run" — the agent is judged to have changed nothing rather than to have gone out of scope.
    expect($outcome['stage'])->toBe('diff_scope');
    expect($outcome['detail'])->toContain('no changes');
    expect($outcome['detail'])->not->toContain('dgepay_payment_driver');
});

it('records the last outcome so the monitor can surface it', function () {
    Process::fake(['*' => Process::result('')]);

    $this->service->attempt('/nonexistent/bundle.js', str_repeat('f', 64), 'structural');

    $last = Cache::get(ExtractorRepairService::LAST_OUTCOME_CACHE_KEY);

    expect($last)->toBeArray();
    expect($last['repaired'])->toBeFalse();
    expect($last)->toHaveKey('at');
});
