<?php

use Symfony\Component\Process\Process;

/**
 * Runs the checkout driver's built-in offline self test.
 *
 * The driver's two riskiest behaviours are pure browser work that no PHP test can reach: filling
 * the wallet number into the boxes the gateway actually reads, and clicking the wallet tile rather
 * than the wrapper that merely contains its name. Both have already broken a live run once, so the
 * self test is executed here instead of being left as a manual command.
 */
function runDriverSelfTest(): array
{
    // One Chrome launch serves every assertion below; a cold headless start costs more than the
    // rest of this suite combined.
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $home = storage_path('framework/testing/payment-driver-home');
    if (! is_dir($home)) {
        mkdir($home, 0775, true);
    }

    $process = new Process(
        ['node', app_path('Scripts/dgepay_payment_driver.cjs'), '--selftest'],
        base_path(),
        [
            // Chrome dies on a CHECK when its crashpad path is not writable.
            'HOME' => $home,
            'PUPPETEER_CACHE_DIR' => storage_path('app/puppeteer'),
        ],
        null,
        120,
    );
    $process->run();

    expect(preg_match('/<<<JSON>>>(.*?)<<<\/JSON>>>/s', $process->getOutput(), $matches))
        ->toBe(1, 'driver emitted no result payload: '.$process->getOutput().$process->getErrorOutput());

    return $cached = json_decode(trim($matches[1]), true);
}

beforeEach(function () {
    if (! is_dir(storage_path('app/puppeteer'))) {
        $this->markTestSkipped('Puppeteer Chrome is not installed on this host.');
    }
});

it('passes the checkout driver self test', function () {
    $result = runDriverSelfTest();

    expect($result['ok'])->toBeTrue($result['error'] ?? 'self test failed');
});

it('types the wallet number into the boxes the gateway reads, not the hidden field', function () {
    $checks = runDriverSelfTest()['checks'];

    expect($checks['merged'])->toBe('01865144147')
        ->and($checks['hidden_untouched'])->toBeTrue();
});

it('tells an unsubmitted OTP click apart from a bank rejection', function () {
    $checks = runDriverSelfTest()['checks'];

    // Link 1301 waited out its whole budget after "OTP received" and reported nothing but a bare
    // timeout, leaving no way to know whether the submit had even landed. The probe now runs before
    // that wait, so the two causes have to stay distinguishable in what it writes.
    expect($checks['stuck_named_as_unsubmitted'])->toBeTrue($checks['submit_stuck_report'])
        ->and($checks['answered_named_as_bank_reply'])->toBeTrue($checks['submit_answered_report']);
});

it('captures a callback handed to a second browser tab', function () {
    $checks = runDriverSelfTest()['checks'];

    // Listeners bound only to the checkout's own page cannot see a popup, and a callback delivered
    // there would look exactly like one that never arrived — the same bare timeout as link 1301.
    expect($checks['popup_callback_captured'])->toBeTrue((string) $checks['popup_callback_seen']);
});

it('clicks the wallet tile and no other wallet', function () {
    $checks = runDriverSelfTest()['checks'];

    // The wrapper around the tiles contains every wallet name. Selecting it reports a successful
    // click while no handler fires, which is how a run reached its step timeout with the method
    // list still on screen; firing a different wallet's tile would be worse still.
    expect($checks['tile_selected'])->toBeTrue($checks['select_error'] ?? '')
        ->and($checks['tiles_fired'])->toBe(['nagad']);
});
