<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountLiveState;
use App\Services\AccountService;
use App\Services\Pdf\PdfRegistrationDate;
use App\Support\AccountLockWindow;
use App\Support\AutoPaymentMethods;
use App\Support\IvacBookingCities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function __construct(
        protected AccountService $service,
        protected PdfRegistrationDate $registrationDate,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('accounts.read');

        $search = $request->query('search');
        $status = $request->query('status');
        // The PDF rows are selected column-by-column on purpose: the list needs each attachment's
        // application ID, and `pdfs` also carries the Base64 payload this query must never load.
        $query = Account::with([
            'user',
            'agentSlot',
            'pdfs' => fn ($q) => $q
                ->select('id', 'account_id', 'name', 'application_id', 'is_primary')
                ->orderByDesc('is_primary')
                ->orderBy('id'),
        ])
            ->withCount('pdfs')
            // One correlated subquery rather than a per-row check, so the paid badge costs nothing
            // on a long list. Mirrors Account::hasCompletedPayment(), watermark included.
            ->withExists(['paymentLinks as auto_payment_paid' => fn ($q) => $q
                ->completed()
                ->where(fn ($inner) => $inner
                    ->whereNull('accounts.auto_payment_rearmed_at')
                    ->orWhereColumn('payment_links.created_at', '>', 'accounts.auto_payment_rearmed_at')
                    ->orWhereColumn('payment_links.callback_submitted_at', '>', 'accounts.auto_payment_rearmed_at'))]);

        $user = $request->user();
        if (($visibleIds = $user->visibleUserIds()) !== null) {
            $query->whereIn('user_id', $visibleIds);
        }

        if ($status && in_array($status, ['running', 'completed', 'cancelled'])) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($qu) use ($search) {
                        $qu->where('name', 'like', "%{$search}%");
                    });
            });
        }

        return $query->latest()->paginate($request->query('per_page', 10));
    }

    /**
     * Latest IVAC API call per account, for the accounts page Live State column.
     *
     * Kept off index() on purpose: the table must render from its own query without waiting on
     * anything the bot writes, so this is fetched separately once the list is on screen and then
     * polled. The response is one pre-summarised row per visible account — no response bodies, no
     * grouping, no join — so a poll stays flat regardless of how much the bot logged.
     */
    public function liveStates(Request $request): JsonResponse
    {
        Gate::authorize('accounts.read');

        $phones = Account::query()->select('phone');

        if (($visibleIds = $request->user()->visibleUserIds()) !== null) {
            $phones->whereIn('user_id', $visibleIds);
        }

        $states = AccountLiveState::query()
            ->select(['phone', 'phase', 'method', 'url', 'status_code', 'duration_ms', 'message', 'error_type', 'logged_at'])
            ->whereIn('phone', $phones)
            ->get();

        return response()->json($states);
    }

    /**
     * Returns a single account with its PDFs (base64) attached. The list endpoint omits the heavy
     * account_pdfs payload for weight; the edit modal fetches the full set from here on demand.
     */
    public function show(Request $request, Account $account)
    {
        Gate::authorize('accounts.read');

        $user = $request->user();
        $visibleIds = $user->visibleUserIds();
        if ($visibleIds !== null && ! in_array($account->user_id, $visibleIds, true)) {
            abort(403);
        }

        $account->load(['user', 'agentSlot']);

        // The PIN is $hidden so it never rides on the account list; the edit form needs it, and
        // this endpoint is already scoped to a single authorized account.
        return array_merge($account->toArray(), [
            'pdfs' => $this->pdfPayload($account),
            'auto_payment_pin' => $account->auto_payment_pin,
        ]);
    }

    /**
     * Validation for the auto-payment credential set.
     *
     * Switching auto payment on commits the account to spending real money unattended, so the
     * method, wallet and PIN are all mandatory at that moment — there is no half-configured state
     * the driver could act on.
     *
     * @param  bool  $requirePin  False on update, where an omitted PIN means "keep the stored one"
     *                            (the caller then verifies one actually exists)
     * @return array<string, mixed>
     */
    private static function autoPaymentRules(bool $requirePin): array
    {
        return [
            'auto_payment' => 'boolean',
            'auto_payment_method' => [
                'nullable',
                'required_if:auto_payment,true,1',
                Rule::in(AutoPaymentMethods::supported()),
            ],
            'auto_payment_wallet' => [
                'nullable',
                'required_if:auto_payment,true,1',
                'string',
                'max:20',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && request()->input('auto_payment_method') === 'rocket' && ! preg_match('/^\d{12}$/', $value)) {
                        $fail('Rocket wallet numbers must be exactly 12 digits.');
                    }
                },
            ],
            'auto_payment_pin' => array_values(array_filter([
                'nullable',
                $requirePin ? 'required_if:auto_payment,true,1' : null,
                'string',
                'max:32',
            ])),
        ];
    }

    /**
     * Reject enabling auto payment on an account that has no PIN stored and none supplied — the
     * "empty means keep" rule must not become a way to arm the driver without a credential.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertAutoPaymentPinPresent(Account $account, array $data): void
    {
        $enabled = (bool) ($data['auto_payment'] ?? $account->auto_payment);
        if (! $enabled) {
            return;
        }

        $hasIncoming = filled($data['auto_payment_pin'] ?? null);
        $hasStored = filled($account->getRawOriginal('auto_payment_pin'));

        if (! $hasIncoming && ! $hasStored) {
            throw ValidationException::withMessages([
                'auto_payment_pin' => 'A payment PIN is required to enable auto payment.',
            ]);
        }
    }

    /**
     * The account's PDFs shaped as the edit form and bot expect: {name, base64, is_primary}.
     *
     * @return list<array{name: ?string, base64: string, is_primary: bool}>
     */
    private function pdfPayload(Account $account): array
    {
        return $account->pdfs()->orderBy('id')->get()
            ->map(fn ($pdf): array => [
                'name' => $pdf->name,
                'base64' => $pdf->base64,
                'is_primary' => (bool) $pdf->is_primary,
                // Viewer-only fields — syncPdfs() ignores them on save and re-derives the optimized
                // copy fresh from `base64`, so echoing them back here is safe. `id` is what lets the
                // account list pick one specific attachment out of this payload after a row click.
                'id' => $pdf->id,
                'application_id' => $pdf->application_id,
                'optimized_base64' => $pdf->optimized_base64,
                'original_size' => $pdf->original_size,
                'optimized_size' => $pdf->optimized_size,
            ])->all();
    }

    /**
     * Rejects newly attached application forms whose printed "Web Registration Date" is more than
     * PdfRegistrationDate::MAX_AGE_DAYS old.
     *
     * Only PDFs the account does not already hold are checked. The edit form round-trips the stored
     * set on every save, so validating all of them would make an account unsavable — an unrelated
     * field edit included — the moment a stored form aged past the limit.
     *
     * @param  array<int, array<string, mixed>>|null  $pdfs
     */
    private function assertFreshPdfs(?array $pdfs, ?Account $account = null): void
    {
        if (! is_array($pdfs) || $pdfs === []) {
            return;
        }

        // Hashed in the database so an account's stored base64 never has to travel into PHP just to
        // work out which of the incoming PDFs are new.
        $stored = $account?->pdfs()->selectRaw('MD5(base64) as content_hash')->pluck('content_hash')->all() ?? [];

        foreach ($pdfs as $index => $pdf) {
            $base64 = $pdf['base64'] ?? null;
            if (! is_string($base64) || $base64 === '' || in_array(md5($base64), $stored, true)) {
                continue;
            }

            $check = $this->registrationDate->inspect($base64);
            if (! $check['expired']) {
                continue;
            }

            $name = is_string($pdf['name'] ?? null) && $pdf['name'] !== '' ? $pdf['name'] : 'This PDF';

            throw ValidationException::withMessages([
                "pdfs.$index.base64" => sprintf(
                    '%s was web-registered on %s, %d days ago. Application forms older than %d days cannot be uploaded.',
                    $name,
                    $check['date']->toFormattedDateString(),
                    $check['age_days'],
                    PdfRegistrationDate::MAX_AGE_DAYS,
                ),
            ]);
        }
    }

    /**
     * Advisory pre-check for the account form: reports a single PDF's web registration date and age
     * so the user is alerted at attach time rather than on save. The save path re-checks it, so this
     * endpoint is never the thing that keeps a stale form out.
     */
    public function inspectPdf(Request $request): JsonResponse
    {
        Gate::authorize('accounts.write');

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'base64' => 'required|string',
        ]);

        $check = $this->registrationDate->inspect($data['base64']);

        return response()->json([
            'web_registration_date' => $check['date']?->toDateString(),
            'age_days' => $check['age_days'],
            'expired' => $check['expired'],
            'readable' => $check['date'] !== null,
            'max_age_days' => PdfRegistrationDate::MAX_AGE_DAYS,
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('accounts.write');

        $data = $request->validate([
            'phone' => 'required|string',
            'email' => 'nullable|email',
            'tag' => 'nullable|string',
            'appointment_id' => 'nullable|string',
            'appointment_dates' => 'nullable|array',
            'appointment_dates.*' => 'date',
            'pdfs' => 'nullable|array',
            'pdfs.*.name' => 'nullable|string|max:255',
            'pdfs.*.base64' => 'required|string',
            'pdfs.*.is_primary' => 'boolean',
            'booking_city' => ['nullable', Rule::in(IvacBookingCities::keys())],
            'password' => 'required|string',
            'is_active' => 'boolean',
            'single_sign_in' => 'boolean',
            'status' => 'nullable|in:running,completed,cancelled',
            'agent_slot_id' => 'nullable|exists:agent_slots,id',
            'max_retries' => 'nullable|integer',
            'retry_delay_ms' => 'nullable|integer',
            'signin_tick_shots' => 'nullable|integer|min:1',
            'signin_tick_interval_ms' => 'nullable|integer|min:100',
            'otp_tick_shots' => 'nullable|integer|min:1',
            'otp_tick_interval_ms' => 'nullable|integer|min:100',
            'slot_tick_shots' => 'nullable|integer|min:1',
            'slot_tick_interval_ms' => 'nullable|integer|min:100',
            'payment_tick_shots' => 'nullable|integer|min:1',
            'payment_tick_interval_ms' => 'nullable|integer|min:100',
            ...self::autoPaymentRules(requirePin: true),
        ]);

        $this->assertSinglePrimaryPdf($data['pdfs'] ?? null);
        $this->assertFreshPdfs($data['pdfs'] ?? null);

        // If a non-running account with the same phone exists, remove it first so the new one can be inserted.
        $existing = Account::where('phone', $data['phone'])->first();
        if ($existing) {
            if ($existing->status === 'running') {
                return response()->json(['message' => 'An account with this phone number is currently running.'], 422);
            }
            $existing->delete();
        }

        $account = $this->service->create($request->user(), $data);

        return $account->load(['user', 'agentSlot']);
    }

    public function update(Request $request, Account $account)
    {
        Gate::authorize('update', $account);

        $data = $request->validate([
            'phone' => 'string',
            'email' => 'nullable|email',
            'tag' => 'nullable|string',
            'appointment_id' => 'nullable|string',
            'appointment_dates' => 'nullable|array',
            'appointment_dates.*' => 'date',
            'pdfs' => 'nullable|array',
            'pdfs.*.name' => 'nullable|string|max:255',
            'pdfs.*.base64' => 'required|string',
            'pdfs.*.is_primary' => 'boolean',
            'booking_city' => ['nullable', Rule::in(IvacBookingCities::keys())],
            'password' => 'string',
            'is_active' => 'boolean',
            'single_sign_in' => 'boolean',
            'status' => 'nullable|in:running,completed,cancelled',
            'agent_slot_id' => 'nullable|exists:agent_slots,id',
            'max_retries' => 'nullable|integer',
            'retry_delay_ms' => 'nullable|integer',
            'signin_tick_shots' => 'nullable|integer|min:1',
            'signin_tick_interval_ms' => 'nullable|integer|min:100',
            'otp_tick_shots' => 'nullable|integer|min:1',
            'otp_tick_interval_ms' => 'nullable|integer|min:100',
            'slot_tick_shots' => 'nullable|integer|min:1',
            'slot_tick_interval_ms' => 'nullable|integer|min:100',
            'payment_tick_shots' => 'nullable|integer|min:1',
            'payment_tick_interval_ms' => 'nullable|integer|min:100',
            'user_id' => 'nullable|exists:users,id',
            'pdf_uploaded' => 'boolean',
            'booking_configured' => 'boolean',
            ...self::autoPaymentRules(requirePin: false),
        ]);

        // An empty PIN on update means "keep the stored one" — the same contract as password, so
        // a form that never received the secret cannot blank it out.
        if (array_key_exists('auto_payment_pin', $data) && blank($data['auto_payment_pin'])) {
            unset($data['auto_payment_pin']);
        }

        $this->assertAutoPaymentPinPresent($account, $data);

        $this->assertSinglePrimaryPdf($data['pdfs'] ?? null);
        $this->assertFreshPdfs($data['pdfs'] ?? null, $account);

        // Reassignment is bounded by the actor's scope: an admin may hand an account to
        // anyone, a manager only to themselves or one of their agents, an agent not at all.
        if (array_key_exists('user_id', $data)) {
            $visibleIds = $request->user()->visibleUserIds();
            if ($visibleIds !== null && ! in_array((int) $data['user_id'], $visibleIds, true)) {
                unset($data['user_id']);
            }
        }

        // Editing the PDFs or the booking city invalidates the setup the bot performed on IVAC,
        // so clear the corresponding flag + date stamp to re-trigger it on the next cycle.
        if (array_key_exists('pdfs', $data) && $this->pdfsChanged($account, $data['pdfs'] ?? [])) {
            $data['pdf_uploaded'] = false;
            $data['pdf_uploaded_date'] = null;
        } elseif (array_key_exists('pdf_uploaded', $data)) {
            // Manual toggle from the table — the config export gates on "today", so stamp/clear the date to match.
            $data['pdf_uploaded_date'] = $data['pdf_uploaded'] ? now('Asia/Dhaka')->toDateString() : null;
        }
        if (array_key_exists('booking_city', $data) && $data['booking_city'] !== $account->booking_city) {
            $data['booking_configured'] = false;
            $data['booking_configured_date'] = null;
        } elseif (array_key_exists('booking_configured', $data)) {
            $data['booking_configured_date'] = $data['booking_configured'] ? now('Asia/Dhaka')->toDateString() : null;
        }

        $account = $this->service->update($account, $data);

        return $account->load(['user', 'agentSlot']);
    }

    /**
     * True when the incoming PDF set differs from what is stored (by name, primary flag, or content
     * hash / order). Used to reset the pdf_uploaded flag only on a real change, so an edit that
     * leaves the PDFs untouched does not force the bot to re-upload.
     *
     * @param  array<int, array<string, mixed>>  $incoming
     */
    private function pdfsChanged(Account $account, array $incoming): bool
    {
        $shape = fn (?string $name, string $base64, mixed $primary): array => [
            'name' => $name,
            'is_primary' => filter_var($primary, FILTER_VALIDATE_BOOLEAN),
            'hash' => md5($base64),
        ];

        $incomingShaped = collect($incoming)
            ->map(fn ($pdf): array => $shape($pdf['name'] ?? null, $pdf['base64'] ?? '', $pdf['is_primary'] ?? false))
            ->all();

        $currentShaped = $account->pdfs()->orderBy('id')->get()
            ->map(fn ($pdf): array => $shape($pdf->name, $pdf->base64, $pdf->is_primary))
            ->all();

        return $incomingShaped !== $currentShaped;
    }

    /**
     * When PDFs are provided, exactly one must be marked primary.
     *
     * @param  array<int, array<string, mixed>>|null  $pdfs
     */
    private function assertSinglePrimaryPdf(?array $pdfs): void
    {
        if (empty($pdfs)) {
            return;
        }

        $primaryCount = collect($pdfs)->filter(fn ($pdf) => filter_var($pdf['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN))->count();

        if ($primaryCount !== 1) {
            throw ValidationException::withMessages([
                'pdfs' => 'Exactly one PDF must be marked as primary.',
            ]);
        }
    }

    /**
     * The lock-window equivalent of the AccountPolicy check, for the batch endpoints that authorize
     * on the permission alone because they have no single model to pass to the policy.
     */
    private function assertNotLocked(Request $request): void
    {
        if (AccountLockWindow::blocks($request->user())) {
            abort(403, AccountLockWindow::MESSAGE);
        }
    }

    public function destroy(Account $account)
    {
        Gate::authorize('delete', $account);
        $this->service->delete($account);

        return response()->noContent();
    }

    public function bulkAssign(Request $request)
    {
        Gate::authorize('accounts.assign');

        $data = $request->validate([
            'account_ids' => 'required|array',
            'account_ids.*' => 'integer|exists:accounts,id',
            'agent_slot_id' => 'nullable|exists:agent_slots,id',
        ]);

        $query = Account::whereIn('id', $data['account_ids']);
        if (($visibleIds = $request->user()->visibleUserIds()) !== null) {
            $query->whereIn('user_id', $visibleIds);
        }

        $updated = $query->update(['agent_slot_id' => $data['agent_slot_id']]);

        return response()->json(['updated' => $updated]);
    }

    /**
     * Batch-toggle the manual PDF-upload / booking-config setup gates across many accounts at once,
     * e.g. from the Accounts table toolbar. Stamps the matching *_date column so the config export's
     * "today" gate (see Account::isPdfUploadedToday/isBookingConfiguredToday) agrees with the flag.
     */
    public function bulkSetupState(Request $request)
    {
        Gate::authorize('accounts.write');
        $this->assertNotLocked($request);

        $data = $request->validate([
            'account_ids' => 'required|array',
            'account_ids.*' => 'integer|exists:accounts,id',
            'pdf_uploaded' => 'nullable|boolean',
            'booking_configured' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        if (! array_key_exists('pdf_uploaded', $data) && ! array_key_exists('booking_configured', $data) && ! array_key_exists('is_active', $data)) {
            throw ValidationException::withMessages([
                'pdf_uploaded' => 'Provide pdf_uploaded, booking_configured, or is_active.',
            ]);
        }

        $query = Account::whereIn('id', $data['account_ids']);
        if (($visibleIds = $request->user()->visibleUserIds()) !== null) {
            $query->whereIn('user_id', $visibleIds);
        }

        $update = [];
        if (array_key_exists('pdf_uploaded', $data)) {
            $update['pdf_uploaded'] = $data['pdf_uploaded'];
            $update['pdf_uploaded_date'] = $data['pdf_uploaded'] ? now('Asia/Dhaka')->toDateString() : null;
        }
        if (array_key_exists('booking_configured', $data)) {
            $update['booking_configured'] = $data['booking_configured'];
            $update['booking_configured_date'] = $data['booking_configured'] ? now('Asia/Dhaka')->toDateString() : null;
        }
        if (array_key_exists('is_active', $data)) {
            $update['is_active'] = $data['is_active'];
        }

        $count = $query->update($update);

        return response()->json(['updated' => $count]);
    }

    /**
     * Clear the paid block on one account so it can be auto-paid again.
     *
     * Auto payment refuses an account that already has a completed payment, because IVAC keeps
     * reissuing checkout links and paying another would charge the wallet twice for one booking.
     * Re-arming is the operator saying this is a genuinely new booking.
     */
    public function rearmAutoPayment(Request $request, Account $account): JsonResponse
    {
        Gate::authorize('accounts.write');

        // Scope before lock: an account the caller cannot see is a 404 whatever the clock says,
        // so the lock window never becomes a way to probe for accounts owned by someone else.
        if (($visibleIds = $request->user()->visibleUserIds()) !== null
            && ! in_array($account->user_id, $visibleIds, true)) {
            abort(404);
        }

        $this->assertNotLocked($request);

        $account->rearmAutoPayment();

        return response()->json([
            'auto_payment_rearmed_at' => $account->auto_payment_rearmed_at,
            'auto_payment_paid' => false,
        ]);
    }

    /**
     * Re-arm many accounts at once, e.g. from the Accounts table toolbar at the start of a new
     * booking window.
     */
    public function bulkRearmAutoPayment(Request $request): JsonResponse
    {
        Gate::authorize('accounts.write');
        $this->assertNotLocked($request);

        $data = $request->validate([
            'account_ids' => 'required|array',
            'account_ids.*' => 'integer|exists:accounts,id',
        ]);

        $query = Account::whereIn('id', $data['account_ids']);
        if (($visibleIds = $request->user()->visibleUserIds()) !== null) {
            $query->whereIn('user_id', $visibleIds);
        }

        $count = $query->update(['auto_payment_rearmed_at' => now()]);

        return response()->json(['updated' => $count]);
    }

    /**
     * Batch-apply the same appointment date range to many accounts at once, e.g. from the Accounts
     * table toolbar. Accepts the already-expanded date array (see the Vue expandDateRange helper) so
     * the same list-of-dates contract as store()/update() is reused.
     */
    public function bulkAppointmentDates(Request $request)
    {
        Gate::authorize('accounts.write');
        $this->assertNotLocked($request);

        $data = $request->validate([
            'account_ids' => 'required|array',
            'account_ids.*' => 'integer|exists:accounts,id',
            'appointment_dates' => 'nullable|array',
            'appointment_dates.*' => 'date',
        ]);

        $query = Account::whereIn('id', $data['account_ids']);
        if (($visibleIds = $request->user()->visibleUserIds()) !== null) {
            $query->whereIn('user_id', $visibleIds);
        }

        // Query-builder updates bypass Eloquent's array cast, so encode explicitly.
        $count = $query->update(['appointment_dates' => json_encode($data['appointment_dates'] ?? [])]);

        return response()->json(['updated' => $count]);
    }
}
