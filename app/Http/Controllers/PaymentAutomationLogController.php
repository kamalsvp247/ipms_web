<?php

namespace App\Http\Controllers;

use App\Models\OtpCode;
use App\Models\PaymentLink;
use App\Support\AutoPaymentMethods;
use App\Support\PaymentAutomationSteps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only auto-payment log for one payment link.
 *
 * Opened in its own tab from /payment-links so an operator can see why an automated checkout
 * succeeded or failed without leaving the list or reading a worker journal.
 */
class PaymentAutomationLogController extends Controller
{
    public function show(Request $request, PaymentLink $paymentLink): Response
    {
        Gate::authorize('accounts.read');

        $user = $request->user();

        // Same visibility rule the payment-links list uses: an agent must not reach another
        // agent's link by guessing an id.
        if (! $user->isSuperAdmin() && ! $user->visibleAccountPhones()->contains($paymentLink->account_phone)) {
            abort(403);
        }

        $paymentLink->load(['account', 'automationAttempt']);
        $attempt = $paymentLink->automationAttempt;
        $account = $paymentLink->account;

        return Inertia::render('PaymentLinks/AutomationLog', [
            'link' => [
                'id' => $paymentLink->id,
                'account_phone' => $paymentLink->account_phone,
                'reservation_id' => $paymentLink->reservation_id,
                'gateway_page_url' => $paymentLink->gateway_page_url,
                'callback_url' => $paymentLink->callback_url,
                'callback_status' => $paymentLink->callback_status,
                'callback_status_code' => $paymentLink->callback_status_code,
                'is_fake' => (bool) $paymentLink->is_fake,
                'created_at' => $paymentLink->created_at?->toJSON(),
                // Drives the same 5-minute countdown shown on /payment-links.
                'expires_at' => $paymentLink->expiresAt()?->toJSON(),
                'expiry_minutes' => PaymentLink::EXPIRY_MINUTES,
                'is_expired' => $paymentLink->isExpired(),
                'is_superseded' => $paymentLink->isSupersededForAccount(),
            ],
            'account' => $account === null ? null : [
                'id' => $account->id,
                'phone' => $account->phone,
                'tag' => $account->tag,
                'auto_payment' => (bool) $account->auto_payment,
                'auto_payment_method' => $account->auto_payment_method,
                'auto_payment_method_label' => AutoPaymentMethods::label($account->auto_payment_method),
                // Wallet is masked: this page is about diagnosing a run, not exposing credentials.
                'auto_payment_wallet' => $this->maskWallet($account->auto_payment_wallet),
            ],
            'attempt' => $attempt === null ? null : [
                'id' => $attempt->id,
                'status' => $attempt->status,
                'method' => $attempt->method,
                'method_label' => AutoPaymentMethods::label($attempt->method),
                'stage' => $attempt->stage,
                'attempts' => $attempt->attempts,
                'max_attempts' => $attempt::MAX_ATTEMPTS,
                'callback_url' => $attempt->callback_url,
                'last_error' => $attempt->last_error,
                'driver_log' => $attempt->driver_log,
                'started_at' => $attempt->started_at?->toJSON(),
                'finished_at' => $attempt->finished_at?->toJSON(),
                'duration_ms' => $attempt->started_at && $attempt->finished_at
                    ? $attempt->finished_at->diffInMilliseconds($attempt->started_at)
                    : null,
            ],
            'steps' => PaymentAutomationSteps::build($attempt?->stage, $attempt?->status),
            // Explains an absent attempt: the reasons the dispatcher would have skipped this link.
            'eligibility' => $attempt !== null ? null : $this->eligibility($paymentLink),
        ]);
    }

    /**
     * Hand a manually-read OTP to a run that is waiting for one.
     *
     * The wallet's SIM is not always on an SMS forwarder, so the code can be sitting on a handset
     * the portal will never see. Rather than teach the driver a second input path, the code is
     * injected into the same `otp_codes` MFS channel the driver already polls — the running
     * driver picks it up on its next 2-second poll with no change to the driver at all.
     */
    public function submitOtp(Request $request, PaymentLink $paymentLink): RedirectResponse
    {
        Gate::authorize('accounts.read');

        $user = $request->user();
        if (! $user->isSuperAdmin() && ! $user->visibleAccountPhones()->contains($paymentLink->account_phone)) {
            abort(403);
        }

        $validated = $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{4,8}$/'],
        ], [
            'otp.regex' => 'The OTP must be 4 to 8 digits.',
        ]);

        $wallet = $paymentLink->account?->auto_payment_wallet;
        if (blank($wallet)) {
            return back()->withErrors(['otp' => 'This account has no auto-payment wallet configured.']);
        }

        OtpCode::create([
            'phone' => $wallet,
            'otp_code' => $validated['otp'],
            'message' => 'Manually entered via the auto payment log by '.$user->name.'.',
            'is_ivacbd' => false,
            'is_mfs' => true,
            'fetched_at' => Carbon::now(config('app.timezone')),
        ]);

        return back()->with('status', 'OTP submitted — the running driver will pick it up within a few seconds.');
    }

    /**
     * Why no automation exists for this link, in the dispatcher's own terms.
     *
     * @return array<string, bool|string|null>
     */
    private function eligibility(PaymentLink $link): array
    {
        $account = $link->account;

        return [
            'is_dgepay' => filled($link->gateway_page_url) && str_contains((string) $link->gateway_page_url, 'dgepay'),
            'has_reservation_id' => filled($link->reservation_id),
            'not_fake' => ! $link->is_fake,
            'no_callback_yet' => blank($link->callback_url),
            'not_already_paid' => ! ($account?->hasCompletedPayment() ?? false),
            'not_expired' => ! $link->isExpired(),
            'is_latest_for_account' => ! $link->isSupersededForAccount(),
            'account_found' => $account !== null,
            'auto_payment_on' => $account?->auto_payment === true,
            'credentials_complete' => $account?->isAutoPaymentReady() ?? false,
        ];
    }

    private function maskWallet(?string $wallet): ?string
    {
        if ($wallet === null || mb_strlen($wallet) < 7) {
            return $wallet;
        }

        return mb_substr($wallet, 0, 3).str_repeat('*', mb_strlen($wallet) - 7).mb_substr($wallet, -4);
    }
}
