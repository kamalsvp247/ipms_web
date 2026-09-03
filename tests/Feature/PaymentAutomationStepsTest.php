<?php

use App\Models\PaymentAutomationAttempt;
use App\Support\PaymentAutomationSteps;

/**
 * @return array<string, string> key => state
 */
function stepStates(?string $stage, ?string $status): array
{
    return collect(PaymentAutomationSteps::build($stage, $status))
        ->mapWithKeys(fn (array $s): array => [$s['key'] => $s['state']])
        ->all();
}

it('marks everything complete on a successful run', function () {
    $states = stepStates('callback', PaymentAutomationAttempt::STATUS_SUCCEEDED);

    expect(collect($states)->unique()->values()->all())->toBe(['complete']);
});

it('splits a running attempt into done, current and upcoming', function () {
    $states = stepStates('await_otp', PaymentAutomationAttempt::STATUS_RUNNING);

    // Everything before the OTP wait is finished...
    expect($states['prepare'])->toBe('complete')
        ->and($states['navigate'])->toBe('complete')
        ->and($states['select_method'])->toBe('complete')
        ->and($states['wallet'])->toBe('complete')
        ->and($states['submit_wallet'])->toBe('complete')
        // ...the OTP wait is happening now...
        ->and($states['await_otp'])->toBe('current')
        // ...and the rest has not started.
        ->and($states['otp'])->toBe('pending')
        ->and($states['pin'])->toBe('pending')
        ->and($states['confirm'])->toBe('pending')
        ->and($states['await_callback'])->toBe('pending')
        ->and($states['callback'])->toBe('pending');
});

it('marks the stage that failed rather than showing it as current', function () {
    $states = stepStates('wallet', PaymentAutomationAttempt::STATUS_FAILED);

    expect($states['select_method'])->toBe('complete')
        ->and($states['wallet'])->toBe('failed')
        ->and($states['submit_wallet'])->toBe('pending');

    expect(collect($states)->filter(fn ($s) => $s === 'current'))->toBeEmpty();
});

it('shows nothing started for a queued attempt', function () {
    $states = stepStates(null, PaymentAutomationAttempt::STATUS_PENDING);

    expect(collect($states)->unique()->values()->all())->toBe(['pending']);
});

it('shows nothing started when no attempt exists', function () {
    $states = stepStates(null, null);

    expect(collect($states)->unique()->values()->all())->toBe(['pending']);
});

it('localises a refused wallet at the OTP request step, not at the OTP wait', function () {
    // The driver now fails at submit_wallet when no OTP field appears, so the checklist must not
    // imply the code was requested and simply never arrived.
    $states = stepStates('submit_wallet', PaymentAutomationAttempt::STATUS_FAILED);

    expect($states['submit_wallet'])->toBe('failed')
        ->and($states['wallet'])->toBe('complete')
        ->and($states['await_otp'])->toBe('pending');
});

it('localises a rejected OTP at the OTP step with the PIN never reached', function () {
    $states = stepStates('otp', PaymentAutomationAttempt::STATUS_FAILED);

    expect($states['await_otp'])->toBe('complete')
        ->and($states['otp'])->toBe('failed')
        ->and($states['pin'])->toBe('pending')
        ->and($states['confirm'])->toBe('pending');
});

it('localises a process-level driver failure at the first step', function () {
    // runDriver() throws (timeout / unparseable payload) before any page state exists.
    $states = stepStates('driver', PaymentAutomationAttempt::STATUS_FAILED);

    expect($states['prepare'])->toBe('failed')
        ->and($states['navigate'])->toBe('pending');
});

it('treats an unrecognised stage as nothing-started rather than guessing', function () {
    $states = stepStates('some_future_stage', PaymentAutomationAttempt::STATUS_FAILED);

    expect(collect($states)->unique()->values()->all())->toBe(['pending']);
});

it('keeps the steps in driver execution order', function () {
    expect(collect(PaymentAutomationSteps::build(null, null))->pluck('key')->all())
        ->toBe([
            'prepare', 'navigate', 'select_method', 'wallet', 'submit_wallet',
            'await_otp', 'otp', 'pin', 'confirm', 'await_callback', 'callback',
        ]);
});

it('localises a contention or paid-guard stop at the first step', function (string $stage) {
    $states = stepStates($stage, PaymentAutomationAttempt::STATUS_FAILED);

    // Without the mapping indexForStage() returns null and every step paints pending, so the
    // failure would vanish from the checklist entirely.
    expect($states['prepare'])->toBe('failed')
        ->and($states['navigate'])->toBe('pending');
})->with(['wallet_busy', 'already_paid']);
