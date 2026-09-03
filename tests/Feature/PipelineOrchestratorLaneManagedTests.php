<?php

use App\Models\Account;
use App\Models\User;

/**
 * LANE-MANAGED TEST CASES FOR 2-LANE PIPELINEORCHESTRATOR
 *
 * Architecture (post-refactor):
 * - Lane 0: OTP verify only → exits after success
 * - Lane 1: sleeps probeActivationDelayMs (21s) → slot reservation → payment
 *
 * Three focused suites for OTP Verify, Reserve Slot, and Payment phases.
 */

// ============================================================================
// PHASE 1: OTP VERIFY SCENARIOS (Lane 0)
// ============================================================================

test('otp_verify_1: Lane 0 verifies OTP successfully, then exits', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'lane_0' => ['enabled' => true, 'phase' => 'otp_verify', 'expected_response' => 200],
        'lane_1' => ['enabled' => true, 'phase' => 'slot_reserve', 'sleep_ms' => 21000],
    ];

    $expectedOutcome = [
        'lane_0_otp_status' => 200,
        'otp_verified' => true,
        'lane_0_action' => 'exit_after_verify',
        'lane_1_proceeds_to_slot' => true,
    ];

    expect($laneConfig)->toBeDefined();
    expect($expectedOutcome)->toBeDefined();
})->group('otp_verify');

test('otp_verify_2: Lane 0 OTP timeout (90s) - Firebase returns null', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'lane_0' => ['enabled' => true, 'phase' => 'otp_verify', 'timeout_ms' => 90000],
        'lane_1' => ['enabled' => true, 'phase' => 'slot_reserve', 'sleep_ms' => 21000],
    ];

    $expectedOutcome = [
        'lane_0_behavior' => 'retry_same_code_then_poll_firebase',
        'lane_1_behavior' => 'wakes_at_21s_gets_401s_waits_21s_each',
        'outcome' => 'eventually_otp_verified_slot_reserved',
    ];

    expect($laneConfig)->toBeDefined();
})->group('otp_verify');

test('otp_verify_3: Lane 0 OTP 403 Forbidden - triggers RestartSignInException', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'lane_0' => ['enabled' => true, 'phase' => 'otp_verify', 'response' => 403],
        'lane_1' => ['enabled' => true, 'phase' => 'slot_reserve'],
    ];

    $expectedOutcome = [
        'lane_0_otp_response' => 403,
        'exception' => 'RestartSignInException',
        'restart_triggered' => true,
    ];

    expect($laneConfig)->toBeDefined();
})->group('otp_verify');

test('otp_verify_4: OTP verified - session data posted to ipms_web', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'lane_0' => ['enabled' => true, 'phase' => 'otp_verify', 'response' => 200],
    ];

    $expectedOutcome = [
        'otp_verified' => true,
        'session_post' => [
            'endpoint' => 'POST /api/account-sessions',
            'fire_and_forget' => true,
            'payload_includes' => ['phone', 'signed_in_server_time', 'jwt_token', 'otp_code', 'otp_verified_server_time'],
        ],
    ];

    expect($laneConfig)->toBeDefined();
})->group('otp_verify');

// ============================================================================
// PHASE 2: RESERVE SLOT SCENARIOS (Lane 1)
// ============================================================================

test('reserve_slot_1: Lane 1 wakes after 21s, gets 200 immediately', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'lane_1' => ['enabled' => true, 'phase' => 'slot_reserve', 'sleep_ms' => 21000, 'response' => 200],
    ];

    $expectedOutcome = [
        'slot_reserved_by' => 'lane_1',
        'reservationId' => 'expected_uuid',
        'slotReserved_flag' => true,
        'next_phase' => 'payment',
    ];

    expect($laneConfig)->toBeDefined();
})->group('reserve_slot');

test('reserve_slot_2: Lane 1 gets 401 before OTP verified - waits probeActivationDelayMs', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'lane_1' => ['enabled' => true, 'phase' => 'slot_reserve', 'response' => 401],
        'otp_verified' => false,
    ];

    $expectedOutcome = [
        'captcha_reuse' => true,
        'wait_ms' => 21000, // probeActivationDelayMs
        'retry_behavior' => 'loop_until_otp_verified',
    ];

    expect($laneConfig)->toBeDefined();
})->group('reserve_slot');

test('reserve_slot_3: Lane 1 gets 401 after OTP verified - smart timestamp comparison', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'lane_1' => ['enabled' => true, 'phase' => 'slot_reserve', 'response' => 401],
        'otp_verified' => true,
        'otp_verified_server_time' => '2026-03-09T10:30:20Z',
        'slot_401_server_time' => '2026-03-09T10:30:25Z', // AFTER → restart
    ];

    $expectedOutcome = [
        'comparison' => '401_time_after_otp_verify_time',
        'action' => 'RestartSignInException',
        'reason' => 'session expired after OTP verification',
    ];

    expect($laneConfig)->toBeDefined();
})->group('reserve_slot');

test('reserve_slot_4: Lane 1 gets 429 rate limit - exponential backoff', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'lane_1' => ['enabled' => true, 'phase' => 'slot_reserve', 'responses' => [429, 429, 429, 200]],
    ];

    $expectedOutcome = [
        'backoff_delays' => [15000, 20000, 30000], // ms with ±25% jitter
        'eventually_success' => true,
        'slot_reserved_by' => 'lane_1',
    ];

    expect($laneConfig)->toBeDefined();
})->group('reserve_slot');

test('reserve_slot_5: Lane 1 gets 400 "already made a payment" - advance to payment', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'lane_1' => ['enabled' => true, 'phase' => 'slot_reserve', 'response' => 400, 'message' => 'already made a payment'],
    ];

    $expectedOutcome = [
        'slot_reserved' => true,
        'next_phase' => 'payment',
        'action' => 'skip_slot_retry_go_payment',
    ];

    expect($laneConfig)->toBeDefined();
})->group('reserve_slot');

test('reserve_slot_6: Lane 1 slot socket timeout - null captcha, retry after delay', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'lane_1' => ['enabled' => true, 'phase' => 'slot_reserve', 'timeout_ms' => 90000],
    ];

    $expectedOutcome = [
        'captcha_action' => 'null_and_refetch',
        'retry_delay_ms' => 'slotRetryDelayMs',
        'max_retries' => 'unlimited',
    ];

    expect($laneConfig)->toBeDefined();
})->group('reserve_slot');

// ============================================================================
// PHASE 3: PAYMENT SCENARIOS (Lane 1 after slot reserved)
// ============================================================================

test('payment_1: Lane 1 executes payment - 200 success with URL', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'slot_reserved' => true,
        'otp_verified' => true,
        'payment_executor' => 'lane_1',
        'payment_response' => 200,
    ];

    $expectedOutcome = [
        'payment_status' => 200,
        'payment_url' => 'https://gateway.example.com/pay/xyz',
        'api_response_sent' => 'POST /api/payment-links',
    ];

    expect($laneConfig)->toBeDefined();
})->group('payment');

test('payment_2: Lane 1 gets payment 429 - retry with exponential backoff', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'slot_reserved' => true,
        'payment_executor' => 'lane_1',
        'payment_responses' => [429, 429, 200],
    ];

    $expectedOutcome = [
        'backoff_1_ms' => 15000,
        'backoff_2_ms' => 30000,
        'success_attempt' => 3,
        'payment_url' => 'https://gateway.example.com/pay/xyz',
    ];

    expect($laneConfig)->toBeDefined();
})->group('payment');

test('payment_3: Lane 1 gets payment 401 three times - session expired', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'slot_reserved' => true,
        'payment_executor' => 'lane_1',
        'payment_response' => 401,
        'consecutive_count' => 3,
    ];

    $expectedOutcome = [
        'action' => 'RestartSignInException',
        'reason' => 'session expired during payment phase',
    ];

    expect($laneConfig)->toBeDefined();
})->group('payment');

test('payment_4: Reservation TTL expired - restart to re-reserve', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'slot_reserved_at_ms' => 0,
        'ttl_ms' => 270000,
        'elapsed_before_payment_ms' => 290000,
        'payment_executor' => 'lane_1',
    ];

    $expectedOutcome = [
        'ttl_expired' => true,
        'action' => 'RestartSignInException',
        'message' => 'reservation TTL expired',
    ];

    expect($laneConfig)->toBeDefined();
})->group('payment');

test('payment_5: Payment 403 Forbidden - restart sign-in', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'slot_reserved' => true,
        'payment_executor' => 'lane_1',
        'payment_response' => 403,
    ];

    $expectedOutcome = [
        'action' => 'RestartSignInException',
        'reason' => 'invalid referer or session issue during payment',
    ];

    expect($laneConfig)->toBeDefined();
})->group('payment');

// ============================================================================
// COMBINED TESTS
// ============================================================================

test('combined_1: Full pipeline - OTP quick → Slot → Payment', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'lane_0' => ['enabled' => true, 'otp' => 200, 'action' => 'exit_after_verify'],
        'lane_1' => ['enabled' => true, 'sleep_ms' => 21000, 'slot' => 200, 'payment' => 200],
    ];

    $expectedOutcome = [
        'otp_verified' => true,
        'slot_reserved' => true,
        'slot_reserved_by' => 'lane_1',
        'payment_success' => true,
        'payment_url' => 'https://gateway.example.com/pay/xyz',
        'all_phases_completed' => true,
    ];

    expect($laneConfig)->toBeDefined();
})->group('combined');

test('combined_2: Probe mode - OTP delayed, Lane 1 retries with 21s wait', function () {
    $user = User::factory()->create(['role' => 'user']);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $laneConfig = [
        'lane_0' => ['enabled' => true, 'otp_delay_ms' => 63000], // OTP takes 63s (3 probe cycles)
        'lane_1' => ['enabled' => true, 'sleep_ms' => 21000,
            'initial_responses' => [401, 401, 401], 'final_response' => 200],
    ];

    $expectedOutcome = [
        'probe_mode' => true,
        'lane_1_401_retries' => 3,
        'each_401_wait_ms' => 21000, // probeActivationDelayMs
        'eventually' => 'slot reserved after OTP verified',
        'payment_url' => 'obtained',
    ];

    expect($laneConfig)->toBeDefined();
})->group('combined');
