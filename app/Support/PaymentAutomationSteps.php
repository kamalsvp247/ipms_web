<?php

namespace App\Support;

use App\Models\PaymentAutomationAttempt;

/**
 * The auto-payment run expressed as an ordered checklist.
 *
 * The driver records a free-form `stage` string as it goes; this maps those onto a fixed sequence
 * of user-facing steps so the log page can show what finished, what is happening now, and what is
 * still ahead.
 */
class PaymentAutomationSteps
{
    public const STATE_COMPLETE = 'complete';

    public const STATE_CURRENT = 'current';

    public const STATE_FAILED = 'failed';

    public const STATE_PENDING = 'pending';

    /**
     * Ordered steps, each absorbing the driver stages that belong to it.
     *
     * `prepare` also catches the process-level stages (`driver`, `input`, `method`) — those mean
     * the run never produced a usable page, so the checklist localises them at the start and the
     * driver console below carries the detail.
     *
     * @var list<array{key: string, label: string, stages: list<string>}>
     */
    private const STEPS = [
        ['key' => 'prepare', 'label' => 'Validate account & credentials', 'stages' => ['resolve', 'credentials', 'queued', 'wallet_busy', 'already_paid', 'method', 'launch', 'input', 'driver', 'expired', 'superseded']],
        ['key' => 'navigate', 'label' => 'Open dg-epay checkout', 'stages' => ['navigate']],
        ['key' => 'select_method', 'label' => 'Select wallet', 'stages' => ['select_method', 'authorize']],
        // DBBL takes the wallet number and the PIN on one form, so `wallet_pin` belongs to this
        // step rather than the later PIN step, which keeps the checklist moving forwards.
        ['key' => 'wallet', 'label' => 'Enter wallet number', 'stages' => ['wallet', 'wallet_pin']],
        ['key' => 'submit_wallet', 'label' => 'Request OTP', 'stages' => ['submit_wallet']],
        ['key' => 'await_otp', 'label' => 'Wait for OTP', 'stages' => ['await_otp']],
        ['key' => 'otp', 'label' => 'Submit OTP', 'stages' => ['otp']],
        ['key' => 'pin', 'label' => 'Enter PIN', 'stages' => ['pin']],
        ['key' => 'confirm', 'label' => 'Confirm payment', 'stages' => ['confirm']],
        ['key' => 'await_callback', 'label' => 'Capture IVAC callback', 'stages' => ['await_callback']],
        ['key' => 'callback', 'label' => 'Deliver callback to bot', 'stages' => ['callback']],
    ];

    /**
     * Build the checklist for an attempt.
     *
     * @param  string|null  $stage  The attempt's last recorded stage
     * @param  string|null  $status  pending|running|succeeded|failed, or null when no attempt exists
     * @return list<array{key: string, label: string, state: string}>
     */
    public static function build(?string $stage, ?string $status): array
    {
        $index = self::indexForStage($stage);

        return array_map(
            fn (array $step, int $i): array => [
                'key' => $step['key'],
                'label' => $step['label'],
                'state' => self::stateFor($i, $index, $status),
            ],
            self::STEPS,
            array_keys(self::STEPS),
        );
    }

    /**
     * Which step a stage belongs to, or null when the stage is unknown/absent.
     */
    private static function indexForStage(?string $stage): ?int
    {
        if ($stage === null || $stage === '') {
            return null;
        }

        foreach (self::STEPS as $i => $step) {
            if (in_array($stage, $step['stages'], true)) {
                return $i;
            }
        }

        return null;
    }

    private static function stateFor(int $i, ?int $current, ?string $status): string
    {
        // A finished run is entirely done regardless of which stage it stopped reporting at.
        if ($status === PaymentAutomationAttempt::STATUS_SUCCEEDED) {
            return self::STATE_COMPLETE;
        }

        // Queued but not started, or no attempt at all: nothing has happened yet.
        if ($status === null || $status === PaymentAutomationAttempt::STATUS_PENDING || $current === null) {
            return self::STATE_PENDING;
        }

        if ($i < $current) {
            return self::STATE_COMPLETE;
        }

        if ($i > $current) {
            return self::STATE_PENDING;
        }

        return $status === PaymentAutomationAttempt::STATUS_FAILED
            ? self::STATE_FAILED
            : self::STATE_CURRENT;
    }
}
