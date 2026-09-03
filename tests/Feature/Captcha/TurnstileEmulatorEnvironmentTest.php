<?php

/**
 * Step 4 of the protocol-emulation plan: the DOM environment Cloudflare's challenge runs in.
 *
 * The emulator executes Cloudflare's own challenge program in node:vm against a stub, and the
 * stub has to be right in ways that produce no error when they are wrong. A missing property
 * is not a crash the challenge reports — it answers a broken environment with a generic
 * `reject: unsupported_browser` or with an eleven-second silent stall, and every identifier in
 * its 260 KB of obfuscated source is machine-generated, so nothing names what was missing.
 *
 * Each assertion in the Node suite is therefore a bug that cost a debugging cycle: Node's own
 * URL.createObjectURL rejecting a stub Blob and failing the capability gate, class prototypes
 * that did not stringify as `[native code]`, the ECMAScript intrinsics being clobbered by the
 * captured browser surface, elements that discarded their load listeners.
 *
 * The suite lives with the emulator because it has to run inside a vm context; this test is
 * what puts it in the project's test run.
 */
it('holds the environment invariants the challenge program depends on', function (): void {
    $script = base_path('app/Scripts/turnstile_emulator.cjs');

    expect(file_exists($script))->toBeTrue();

    $process = \Symfony\Component\Process\Process::fromShellCommandline(
        'node '.escapeshellarg($script).' --self-test 2>&1'
    );
    $process->setTimeout(120);
    $process->run();

    $output = trim($process->getOutput());
    $report = json_decode($output, true);

    expect($report)->toBeArray(
        "self-test produced no JSON report:\n{$output}"
    );

    expect($report['failures'])->toBe(
        [],
        'failing checks: '.json_encode($report['failures'], JSON_PRETTY_PRINT)
    );

    expect($report['passed'])->toBe($report['checks']);
    expect($process->getExitCode())->toBe(0);
});

/**
 * The captured browser surface the stub is built from.
 *
 * It is measured rather than hand-written — around 950 interface constructors and 1,250 window
 * properties, which no list maintained by hand stays right about. Re-capture it with
 * turnstile_dom_capture.cjs when Chrome moves. A stale capture narrows the stub; a malformed
 * one silently removes whole interfaces, so the shape is asserted here.
 */
it('ships a well-formed DOM surface capture', function (): void {
    $path = storage_path('app/captcha/turnstile_dom_surface.json');

    if (! file_exists($path)) {
        $this->markTestSkipped('no DOM surface captured yet — run turnstile_dom_capture.cjs');
    }

    $captured = json_decode(file_get_contents($path), true);

    expect($captured)->toBeArray();
    expect($captured['surface'])->toBeArray();
    expect($captured['globals'])->toBeArray();

    // Interfaces the challenge was measured reaching for.
    foreach (['Element', 'Node', 'HTMLElement', 'Document', 'CustomEvent', 'RTCPeerConnection'] as $interface) {
        expect($captured['surface'])->toHaveKey($interface);
        expect($captured['surface'][$interface])->toHaveKeys(['proto', 'statics', 'constructible']);
    }

    // Constructibility is per-interface and load-bearing in both directions: refusing to
    // construct a CustomEvent stops the bootstrap on its first statement, and allowing
    // `new Element()` contradicts every browser.
    expect($captured['surface']['CustomEvent']['constructible'])->toBeTrue();
    expect($captured['surface']['Element']['constructible'])->toBeFalse();

    // Constants have to survive as values rather than as inert methods.
    expect($captured['surface']['Node']['statics']['ELEMENT_NODE'])
        ->toBe(['kind' => 'value', 'value' => 1]);

    // The null-valued handlers are what `'onmessage' in window` answers with.
    expect($captured['globals']['onmessage'])->toBe(['kind' => 'value', 'value' => null]);
    expect($captured['globals']['visualViewport']['kind'])->toBe('object');
});

/**
 * The capture must not carry Cloudflare's OWN runtime globals.
 *
 * It is taken from a live iframe after the challenge has run, so its snapshot includes the
 * names the challenge itself defined — window.runProgram among them. Materialising those hands
 * the next run a pre-existing inert stub where it expects to build its own, which is strictly
 * worse than the name being absent: the challenge finds it and calls it, and nothing happens.
 *
 * They are identified by measurement, not by a name pattern — the same probe runs against the
 * parent page, an ordinary window in the same Chrome that never ran a challenge, and anything
 * present only in the iframe is Cloudflare's.
 */
it('excludes the challenge\'s own runtime globals from the capture', function (): void {
    $path = storage_path('app/captcha/turnstile_dom_surface.json');

    if (! file_exists($path)) {
        $this->markTestSkipped('no DOM surface captured yet — run turnstile_dom_capture.cjs');
    }

    $captured = json_decode(file_get_contents($path), true);

    expect($captured['challenge_owned'])->toBeArray();
    expect($captured['challenge_owned'])->toContain('runProgram');

    foreach ($captured['challenge_owned'] as $name) {
        expect($captured['globals'])->not->toHaveKey($name);
        expect($captured['surface'])->not->toHaveKey($name);
    }

    // Real browser globals must survive the exclusion.
    foreach (['visualViewport', 'customElements', 'Element', 'fetch'] as $name) {
        expect($captured['globals'])->toHaveKey($name);
    }
});
