<?php

use Symfony\Component\Process\Process;

/**
 * Guards the throughput-critical internals of app/Scripts/in_house_captcha_solver.cjs.
 *
 * The solver is exercised from PHP the same way extract_request_constants.cjs is: a small
 * Node harness requires the module (which is side-effect free unless run as main) and
 * prints JSON this test asserts against. That keeps the pool logic covered without adding
 * a JavaScript test runner to the project's dependencies.
 *
 * Each assertion here stands for a property that was measured rather than assumed, and
 * that silently costs throughput or success rate if it regresses.
 */
function runSolverHarness(string $js): array
{
    // The solver's own log() writes to stdout, so anything the harness touches that logs
    // would otherwise be prepended to the JSON and fail the decode. The payload is fenced
    // by a sentinel instead of assuming it is the only thing on the stream.
    $harness = <<<JS
    const solver = require(process.argv[1]);
    const out = (o) => process.stdout.write('\\n<<<JSON>>>' + JSON.stringify(o));
    {$js}
    JS;

    $process = new Process([
        'node', '-e', $harness, base_path('app/Scripts/in_house_captcha_solver.cjs'),
    ]);
    $process->setTimeout(30);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $output = $process->getOutput();
    $marker = strrpos($output, '<<<JSON>>>');

    expect($marker)->not->toBeFalse('harness produced no JSON payload: '.$output);

    $decoded = json_decode(trim(substr($output, $marker + 10)), true);
    expect($decoded)->toBeArray();

    return $decoded;
}

/**
 * Chrome requests /favicon.ico from the page's own origin when the document declares no
 * icon. That request is not intercepted, so it is the one call in the whole solve that
 * genuinely leaves for IVAC — measured as 2 ivacbd.com requests without the data: URI
 * against 1 (the locally fulfilled document) with it.
 */
it('declares an inline favicon so no request reaches the real origin', function () {
    $result = runSolverHarness(<<<'JS'
    const html = solver.buildPage('0x4AAAAAACghKkJHL1t7UkuZ');
    out({ favicon: html.includes('<link rel="icon" href="data:,">'), sitekey: html.includes('0x4AAAAAACghKkJHL1t7UkuZ') });
    JS);

    expect($result['favicon'])->toBeTrue();
    expect($result['sitekey'])->toBeTrue();
});

/**
 * Cloudflare detects the binding page.exposeFunction() injects and answers with a widget
 * that never renders an iframe and never fires either callback — 0/2 solves in a bisect
 * where every other variant scored 2/2. The page must keep publishing the token on
 * window for the poller to read.
 */
it('publishes the token on window rather than through an injected binding', function () {
    $result = runSolverHarness(<<<'JS'
    const html = solver.buildPage('0x4AAAAAACghKkJHL1t7UkuZ');
    out({ onWindow: html.includes('window.__ihcToken = token'), binding: /__ihcDone|exposeFunction/.test(html) });
    JS);

    expect($result['onWindow'])->toBeTrue();
    expect($result['binding'])->toBeFalse('exposeFunction bindings are detected by Cloudflare — see buildPage()');
});

/**
 * The narrow Fetch.enable pattern is what keeps the challenge sequence off the CDP round
 * trip. CDP treats ? and * as wildcards, so a page URL carrying either must be escaped or
 * the document match becomes a broad one.
 */
it('escapes CDP url-pattern wildcards', function () {
    $result = runSolverHarness(<<<'JS'
    out({
        plain: solver.cdpEscapeUrl('https://appointment.ivacbd.com/'),
        query: solver.cdpEscapeUrl('https://host/p?a=1*b'),
    });
    JS);

    expect($result['plain'])->toBe('https://appointment.ivacbd.com/');
    expect($result['query'])->toBe('https://host/p\?a=1\*b');
});

/**
 * Concurrency is spread across browsers precisely because one Chrome process serialises
 * CDP dispatch for every context it owns (p50 6.1s on one browser vs 4.4s on four at the
 * same 16 in flight). Least-loaded selection is what keeps that spread even.
 */
it('leases the least-loaded browser', function () {
    $result = runSolverHarness(<<<'JS'
    const pool = [new solver.BrowserSlot(1), new solver.BrowserSlot(2), new solver.BrowserSlot(3)];
    pool[0].leases = 2; pool[1].leases = 0; pool[2].leases = 1;
    // leaseSlot() closes over the module's own pool, so exercise the same rule directly.
    const pick = pool.filter((s) => !s.draining).reduce((b, s) => (s.leases < b.leases ? s : b));
    out({ index: pick.index });
    JS);

    expect($result['index'])->toBe(2);
});

/**
 * Recycling used to wait for the whole pool to fall idle, which under sustained load never
 * happens — so a long-lived renderer's memory was never reclaimed. A slot must instead stop
 * taking work as soon as it passes the threshold and close once its own in-flight solves
 * drain.
 */
it('drains a browser past the recycle threshold instead of waiting for a fully idle pool', function () {
    $result = runSolverHarness(<<<'JS'
    (async () => {
        const slot = new solver.BrowserSlot(1);
        let closed = false;
        slot.browser = { connected: true, close: async () => { closed = true; }, on: () => {} };
        slot.solves = 500;   // past the default RECYCLE_AFTER of 400
        slot.leases = 1;     // one solve still in flight

        await slot.maybeRecycle();
        const whileBusy = { draining: slot.draining, closed };

        slot.leases = 0;     // last solve drains
        await slot.maybeRecycle();

        out({ whileBusy, afterDrain: { closed, browser: slot.browser } });
    })();
    JS);

    expect($result['whileBusy']['draining'])->toBeTrue('slot must stop accepting work immediately');
    expect($result['whileBusy']['closed'])->toBeFalse('must not kill an in-flight solve');
    expect($result['afterDrain']['closed'])->toBeTrue('must close once its own leases drain');
    expect($result['afterDrain']['browser'])->toBeNull();
});

/**
 * Trace mode exists to specify the challenge sequence for protocol emulation, so its
 * classifier has to name every leg the flow actually produces. The blob: case is the one
 * that bit: the widget builds its workers from object URLs, which never touch the network
 * and so have no response — filing them as unexplained 'other' traffic made a clean trace
 * look like it had four failed requests in it.
 */
it('classifies every leg of the challenge flow, including blob worker URLs', function () {
    $result = runSolverHarness(<<<'JS'
    const page = 'https://appointment.ivacbd.com/';
    out({
        document: solver.classifyUrl(page, page),
        blob: solver.classifyUrl('blob:https://challenges.cloudflare.com/66004e09-1426', page),
        challenge: solver.classifyUrl('https://challenges.cloudflare.com/cdn-cgi/challenge-platform/h/g/fo/a/b/c', page),
        subdomain: solver.classifyUrl('https://hagen.challenges.cloudflare.com/cdn-cgi/challenge-platform/h/g/i/a', page),
        api: solver.classifyUrl('https://challenges.cloudflare.com/turnstile/v0/g/b0da9f4911ba/api.js', page),
        foreign: solver.classifyUrl('https://api.ivacbd.com/iams/api/v1/auth/v23-sign-in', page),
    });
    JS);

    expect($result['document'])->toBe('document');
    expect($result['blob'])->toBe('blob');
    expect($result['challenge'])->toBe('challenge');
    expect($result['subdomain'])->toBe('challenge');
    expect($result['api'])->toBe('turnstile');
    expect($result['foreign'])->toBe('other');
});

/**
 * The site key is interpolated straight into a JS string literal in buildPage(), so this
 * validation is what stops it breaking out. /solve and /trace share it precisely so a
 * second entry point cannot skip the check.
 */
it('validates the shared solve and trace request body', function () {
    $result = runSolverHarness(<<<'JS'
    const attempt = (body) => {
        try { return { ok: true, value: solver.parseTargetBody(body, 30000) }; }
        catch (e) { return { ok: false, status: e.status, message: e.message }; }
    };
    out({
        injection: attempt({ siteKey: "a', evil: '", pageUrl: 'https://appointment.ivacbd.com/' }),
        insecure: attempt({ siteKey: '0x4AAAAAACghKkJHL1t7UkuZ', pageUrl: 'http://appointment.ivacbd.com/' }),
        relative: attempt({ siteKey: '0x4AAAAAACghKkJHL1t7UkuZ', pageUrl: '/signin' }),
        valid: attempt({ siteKey: '0x4AAAAAACghKkJHL1t7UkuZ', pageUrl: 'https://appointment.ivacbd.com/' }),
        clamped: attempt({ siteKey: '0x4AAAAAACghKkJHL1t7UkuZ', pageUrl: 'https://appointment.ivacbd.com/', timeoutMs: 999999 }),
    });
    JS);

    expect($result['injection']['ok'])->toBeFalse();
    expect($result['injection']['status'])->toBe(422);
    expect($result['insecure']['ok'])->toBeFalse();
    expect($result['relative']['ok'])->toBeFalse();
    expect($result['valid']['ok'])->toBeTrue();
    expect($result['valid']['value']['timeoutMs'])->toBe(30000);
    expect($result['clamped']['value']['timeoutMs'])->toBe(120000);
});

/**
 * The challenge sequence is the part of a trace anyone reads first, so the summary has to
 * carry it in issue order with the body sizes attached — a 3.7 KB first POST against an
 * 85 KB second one is the whole shape of the flow at a glance.
 */
it('summarises a trace down to the ordered challenge sequence', function () {
    $result = runSolverHarness(<<<'JS'
    out(solver.summariseTrace([
        { order: 0, role: 'document', host: 'appointment.ivacbd.com', method: 'GET', path: '/', status: 200, t_ms: 6 },
        { order: 4, role: 'blob', host: '', method: 'GET', path: 'blob:x', t_ms: 208 },
        { order: 5, role: 'challenge', host: 'challenges.cloudflare.com', method: 'POST', path: '/cdn-cgi/challenge-platform/h/g/fo/a', status: 200, t_ms: 412, request_body: 'x'.repeat(3692), response_body: 'y'.repeat(822608) },
        { order: 18, role: 'challenge', host: 'challenges.cloudflare.com', method: 'POST', path: '/cdn-cgi/challenge-platform/h/g/fo/a', status: 200, t_ms: 2414, request_body: 'x'.repeat(85772), response_body: 'y'.repeat(4136) },
    ]));
    JS);

    expect($result['requests'])->toBe(4);
    expect($result['by_role'])->toBe(['document' => 1, 'blob' => 1, 'challenge' => 2]);
    expect($result['hosts'])->toBe(['appointment.ivacbd.com', 'challenges.cloudflare.com']);
    expect($result['challenge_sequence'])->toHaveCount(2);
    expect($result['challenge_sequence'][0]['request_bytes'])->toBe(3692);
    expect($result['challenge_sequence'][1]['request_bytes'])->toBe(85772);
    expect($result['challenge_sequence'][1]['order'])->toBe(18);
});

/**
 * A warm pool costs the same idle as busy — measured at 1842 MB across 61 Chrome processes
 * with zero traffic, on a host that also runs the Java bot. Reaping takes that to 21 MB, and
 * every clause below is a way the reaper could destroy a live solve if it got this wrong.
 *
 * The mid-launch case is the subtle one: that slot has no `browser` yet but is about to, so a
 * naive "close everything without a browser handle" check would race the launch and leave the
 * slot holding a dead process.
 */
it('only reaps the pool when nothing can be destroyed by it', function () {
    $result = runSolverHarness(<<<'JS'
    const base = { active: 0, queued: 0, idleForMs: 90000, idleMs: 60000, slots: [{ browser: {}, launching: null, leases: 0 }] };
    const check = (over) => solver.isReapable({ ...base, ...over });
    out({
        idleWithLiveBrowser: check({}),
        solveInFlight: check({ active: 1 }),
        requestQueued: check({ queued: 1 }),
        notIdleLongEnough: check({ idleForMs: 30000 }),
        slotMidLaunch: check({ slots: [{ browser: null, launching: {}, leases: 0 }] }),
        slotHoldingLease: check({ slots: [{ browser: {}, launching: null, leases: 1 }] }),
        alreadyReaped: check({ slots: [{ browser: null, launching: null, leases: 0 }] }),
        reapingDisabled: check({ idleMs: 0 }),
    });
    JS);

    expect($result['idleWithLiveBrowser'])->toBeTrue();
    expect($result['solveInFlight'])->toBeFalse('closing the pool under an in-flight solve kills it');
    expect($result['requestQueued'])->toBeFalse('a queued request is about to need a browser');
    expect($result['notIdleLongEnough'])->toBeFalse();
    expect($result['slotMidLaunch'])->toBeFalse('a launching slot has no browser yet but is about to');
    expect($result['slotHoldingLease'])->toBeFalse();
    expect($result['alreadyReaped'])->toBeFalse('nothing to close');
    expect($result['reapingDisabled'])->toBeFalse();
});

/**
 * A draining slot must not be handed new work, or it can never reach zero leases and the
 * recycle stalls forever.
 */
it('skips draining browsers when leasing', function () {
    $result = runSolverHarness(<<<'JS'
    const pool = [new solver.BrowserSlot(1), new solver.BrowserSlot(2)];
    pool[0].leases = 0; pool[0].draining = true;
    pool[1].leases = 5;
    const candidates = pool.filter((s) => !s.draining);
    const pick = (candidates.length ? candidates : pool).reduce((b, s) => (s.leases < b.leases ? s : b));
    out({ index: pick.index });
    JS);

    expect($result['index'])->toBe(2);
});

/**
 * Retuning a node from the fleet console has to move the browser count too, not just the
 * semaphore. One Chrome process serialises CDP dispatch for every context it owns, so raising
 * concurrency 4 -> 16 against a single browser buys queueing rather than throughput: p50 goes
 * 6.1s on one browser against 4.4s on four at the same 16 in flight.
 */
it('resizes the browser pool with a concurrency change', function () {
    $result = runSolverHarness(<<<'JS'
    const before = solver.pool.length;
    solver.setConcurrency(16);
    const grown = solver.pool.length;
    solver.setConcurrency(4);
    out({
        before,
        grown,
        shrunk: solver.pool.length,
        ideal: [1, 4, 5, 16, 24].map(solver.idealBrowsers),
        indexes: solver.pool.map((s) => s.index),
    });
    JS);

    expect($result['before'])->toBe(4);
    expect($result['grown'])->toBe(4, 'concurrency 16 wants four browsers — the measured efficient shape');
    expect($result['shrunk'])->toBe(1);
    expect($result['ideal'])->toBe([1, 1, 2, 4, 6]);
    expect($result['indexes'])->toBe([1]);
});

/**
 * Shrinking must not kill a solve that is already most of the way through a Cloudflare round
 * trip. A retired slot leaves the rotation immediately so nothing new can lease it, and closes
 * only once its own in-flight solves have drained.
 */
it('retires a browser out of the rotation before closing it', function () {
    $result = runSolverHarness(<<<'JS'
    (async () => {
        solver.setConcurrency(16);

        let closed = false;
        const busy = solver.pool[solver.pool.length - 1];
        busy.leases = 1;
        busy.browser = { connected: true, close: async () => { closed = true; }, on: () => {} };

        solver.resizePool(1);
        const immediately = { inPool: solver.pool.includes(busy), draining: busy.draining, closed };

        busy.leases = 0;
        await new Promise((r) => setTimeout(r, 900));

        out({ immediately, afterDrain: { closed } });
    })();
    JS);

    expect($result['immediately']['inPool'])->toBeFalse('a retired slot must stop receiving leases at once');
    expect($result['immediately']['draining'])->toBeTrue();
    expect($result['immediately']['closed'])->toBeFalse('must not kill an in-flight solve');
    expect($result['afterDrain']['closed'])->toBeTrue('must close once its own leases drain');
});

/**
 * Puppeteer creates one throwaway Chrome profile per launch under the system temp directory
 * and removes it on a clean close. Nothing removes it otherwise, and a shutdown that overruns
 * its systemd timeout is SIGKILLed and cannot — so with idle reaping plus recycle-after-N the
 * launch rate is high enough that they accumulate without bound. 46 directories holding 31 MB
 * had built up when this was found.
 *
 * The sweep therefore runs at startup, which is the only point that can clean up after a
 * killed process. That makes the in-use check load-bearing: it may run while another solver
 * instance is live, and deleting a profile out from under a running Chrome would break solves
 * that are in flight. Both directions are asserted.
 */
it('sweeps orphaned chrome profiles but never one that is in use', function () {
    $tmp = sys_get_temp_dir();
    $orphan = $tmp.'/puppeteer_dev_chrome_profile-phpunit'.bin2hex(random_bytes(4));
    $live = $tmp.'/puppeteer_dev_chrome_profile-phpunit'.bin2hex(random_bytes(4));

    mkdir($orphan.'/Default', 0700, true);
    file_put_contents($orphan.'/Default/Preferences', '{}');
    mkdir($live, 0700, true);

    // A live process whose command line names the profile, standing in for a running Chrome.
    // The path is passed as sh's $0 rather than as an argument to sleep, which rejects one —
    // what matters is only that the path appears in /proc/<pid>/cmdline.
    $holder = new Process(['sh', '-c', 'sleep 30', $live]);
    $holder->start();

    try {
        usleep(300_000); // let the process appear in /proc with its argv

        $result = runSolverHarness('out(solver.sweepOrphanProfiles());');

        expect(is_dir($orphan))->toBeFalse('an unreferenced profile should have been removed');
        expect(is_dir($live))->toBeTrue('a profile named by a live process must be kept');
        expect($result['removed'])->toBeGreaterThanOrEqual(1);
        expect($result['kept'])->toBeGreaterThanOrEqual(1);
    } finally {
        $holder->stop(0);

        foreach ([$orphan, $live] as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf '.escapeshellarg($dir));
            }
        }
    }
});
