<?php

namespace App\Http\Controllers\Api;

use App\Events\PaymentLinkCreated;
use App\Http\Controllers\Controller;
use App\Jobs\FetchInvoiceJob;
use App\Models\AccountSession;
use App\Models\PaymentLink;
use App\Models\Setting;
use App\Services\Captcha\RawCaptchaTokenProvider;
use App\Services\Payment\AutoPaymentDispatcher;
use App\Services\Payment\InvoiceArchive;
use App\Services\Payment\PaymentCallbackRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

class PaymentLinkController extends Controller
{
    /**
     * Public ingest: accept IVAC payment/ssl/initiate response and store.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'data' => 'required|array',
            'data.GatewayPageURL' => 'nullable|string|max:2048',
            'data.redirectGatewayURL' => 'nullable|string|max:2048',
            'data.webview_url' => 'nullable|string|max:2048',
            'statusCode' => 'nullable|integer',
            'message' => 'nullable|string|max:255',
            'successFlag' => 'nullable|boolean',
            'serverTime' => 'nullable|string|max:64',
            'account_phone' => 'nullable|string|max:32',
            'reservation_id' => 'nullable|string|max:128',
        ]);

        $data = $validated['data'] ?? [];
        $accountPhone = $validated['account_phone'] ?? null;

        // Try to find the account by phone number
        $accountId = null;
        if ($accountPhone) {
            $account = \App\Models\Account::where('phone', $accountPhone)->first();
            $accountId = $account?->id;
        }

        $link = PaymentLink::create([
            'account_id' => $accountId,
            'account_phone' => $accountPhone,
            'reservation_id' => $validated['reservation_id'] ?? null,
            'gateway_page_url' => $data['GatewayPageURL'] ?? $data['webview_url'] ?? null,
            'redirect_gateway_url' => $data['redirectGatewayURL'] ?? null,
            'status_code' => $validated['statusCode'] ?? null,
            'message' => $validated['message'] ?? null,
            'success_flag' => $validated['successFlag'] ?? null,
            'server_time' => $validated['serverTime'] ?? null,
            'payload' => $request->all(),
        ]);

        // Auto payment starts the moment the link exists. Never let a queue hiccup fail the bot's
        // ingest — the per-minute sweep re-dispatches anything that does not get through here.
        try {
            app(AutoPaymentDispatcher::class)->dispatchFor($link);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            broadcast(new PaymentLinkCreated($accountPhone, $link->gateway_page_url));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($link, 201);
    }

    /**
     * Public REST endpoint: list payment links by date range (no auth).
     * Payload: { "from_date": "YYYY-MM-DD", "to_date": "YYYY-MM-DD" } — both optional.
     */
    public function publicList(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date_format:Y-m-d',
            'to_date' => 'nullable|date_format:Y-m-d',
        ]);

        $query = PaymentLink::query();

        if (! empty($validated['from_date'])) {
            $query->whereDate('created_at', '>=', $validated['from_date']);
        }
        if (! empty($validated['to_date'])) {
            $query->whereDate('created_at', '<=', $validated['to_date']);
        }

        $links = $query
            ->orderByDesc('created_at')
            ->get(['id', 'account_phone', 'gateway_page_url', 'redirect_gateway_url', 'status_code', 'message', 'success_flag', 'server_time', 'created_at']);

        return response()->json([
            'count' => $links->count(),
            'from_date' => $validated['from_date'] ?? null,
            'to_date' => $validated['to_date'] ?? null,
            'data' => $links,
        ]);
    }

    /**
     * List payment links grouped by account (one row per account).
     * Each group includes the first (oldest) link, links_count, and all_links
     * (all_links is ordered newest-first so the modal shows the latest checkout link on top).
     * Supports filtering by date range and phone number.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('accounts.read');

        $user = $request->user();
        $perPage = (int) $request->query('per_page', 15);
        $page = (int) $request->query('page', 1);
        $fromDate = $request->query('from_date');
        $toDate = $request->query('to_date');
        $phone = $request->query('phone');
        $visaType = $request->query('visa_type');
        $reviewStatus = $request->query('review_status');
        $callbackStatus = $request->query('callback_status');
        $clicked = $request->query('clicked');

        // `payload` and `is_fake` are never read by this listing (payload is only written on
        // ingest; fakes are purged), so they're left off the select to keep the in-memory
        // group-by/paginate below cheap on a wide date range.
        $query = PaymentLink::query()
            ->select([
                'id', 'account_id', 'account_phone', 'reservation_id', 'gateway_page_url',
                'redirect_gateway_url', 'callback_url', 'callback_status', 'callback_status_code',
                'callback_response', 'callback_submitted_at', 'callback_completed_at', 'status_code',
                'message', 'success_flag', 'is_clicked', 'review_status', 'server_time',
                'invoice_path', 'invoice_fetched_at', 'created_at', 'updated_at',
            ])
            ->with('account.user');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('account_phone', $user->visibleAccountPhones());
        }

        if ($fromDate) {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('created_at', '<=', $toDate);
        }
        if ($phone) {
            $query->where('account_phone', 'like', '%'.$phone.'%');
        }
        if ($visaType) {
            $query->whereHas('accountSession', function ($q) use ($visaType) {
                if ($visaType === 'UNKNOWN') {
                    $q->whereNull('reserved_visa_type');
                } else {
                    $q->where('reserved_visa_type', $visaType);
                }
            });
        }
        if ($reviewStatus) {
            $query->where('review_status', $reviewStatus);
        }
        if ($callbackStatus) {
            if ($callbackStatus === 'none') {
                $query->whereNull('callback_status');
            } else {
                $query->where('callback_status', $callbackStatus);
            }
        }
        if ($clicked === 'yes') {
            $query->where('is_clicked', true);
        } elseif ($clicked === 'no') {
            $query->where('is_clicked', false);
        }

        $grouped = $query
            ->orderBy('created_at', 'asc')
            ->get()
            ->groupBy(fn ($link) => $link->account_phone.'|'.$link->created_at->toDateString())
            ->map(function ($links) {
                $data = $links->first()->toArray();
                $data['links_count'] = $links->count();
                $data['all_links'] = $links->sortByDesc('created_at')->values()->map->toArray();
                $data['group_date'] = $links->first()->created_at->toDateString();
                $data['latest_at'] = $links->max('created_at')->toJSON();

                return $data;
            })
            ->sortByDesc('latest_at')
            ->values();

        $total = $grouped->count();
        $page_items = $grouped->forPage($page, $perPage)->values();

        // Enrich with account session data (visa type, applicants, amount, application ID)
        $phones = $page_items->pluck('account_phone')->filter()->unique()->values();
        $sessions = AccountSession::whereIn('phone', $phones)
            ->get(['phone', 'last_booking_config', 'last_file_overview', 'reserved_visa_type', 'reserved_applicants', 'jwt_expires_at'])
            ->keyBy('phone');

        $items = $page_items->map(function ($item) use ($sessions) {
            $session = $sessions->get($item['account_phone'] ?? '');
            $bookingConfig = $session?->last_booking_config['data'] ?? null;
            $overviewItems = $session?->last_file_overview['items'] ?? [];
            $primary = collect($overviewItems)->firstWhere('primary', true) ?? $overviewItems[0] ?? null;

            // Prefer the visa type + applicant count captured from the reserveSlot response
            // (authoritative), falling back to the per-account session config when absent.
            $item['visa_type'] = $session?->reserved_visa_type ?? ($primary['visaType'] ?? null);
            $item['application_id'] = $primary['applicationId'] ?? null;
            $item['number_of_applicants'] = $session?->reserved_applicants ?? ($bookingConfig['numberOfApplicants'] ?? null);
            $item['total_amount'] = $bookingConfig['totalAmount'] ?? null;
            $item['jwt_expires_at'] = $session?->jwt_expires_at?->toJSON();

            return $item;
        });

        return response()->json([
            'data' => $items,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage) ?: 1,
            'total' => $total,
            'from' => $total > 0 ? ($page - 1) * $perPage + 1 : null,
            'to' => $total > 0 ? min($page * $perPage, $total) : null,
        ]);
    }

    /**
     * Update the manual review status (unread / declined / succeeded).
     */
    public function updateReviewStatus(Request $request, PaymentLink $paymentLink): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $user->ownsSubtree(), 403);

        if (! $user->isSuperAdmin()) {
            abort_unless(in_array($paymentLink->account_phone, $user->visibleAccountPhones()->all(), true), 403);
        }

        $validated = $request->validate([
            'review_status' => 'required|in:unread,declined,succeeded',
        ]);

        $paymentLink->update(['review_status' => $validated['review_status']]);

        return response()->json(['review_status' => $paymentLink->review_status]);
    }

    /**
     * Update the reserved visa type + applicant count for the account behind a payment link.
     * This edits the underlying account_sessions row (the same source the monthly report
     * reads from), so the change is reflected everywhere that data is displayed.
     */
    public function updateVisaInfo(Request $request, PaymentLink $paymentLink): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $validated = $request->validate([
            'visa_type' => 'required|string|max:64',
            'number_of_applicants' => 'required|integer|min:0',
        ]);

        $phone = $paymentLink->account_phone;
        if ($phone === null) {
            return response()->json(['message' => 'This payment link has no associated account.'], 422);
        }

        $session = AccountSession::updateOrCreate(
            ['phone' => $phone],
            [
                'reserved_visa_type' => $validated['visa_type'],
                'reserved_applicants' => $validated['number_of_applicants'],
            ]
        );

        return response()->json([
            'visa_type' => $session->reserved_visa_type,
            'number_of_applicants' => $session->reserved_applicants,
        ]);
    }

    /**
     * Distinct visa types currently present in account_sessions, for the edit dropdown.
     */
    public function visaTypes(): JsonResponse
    {
        Gate::authorize('accounts.read');

        $types = AccountSession::query()
            ->whereNotNull('reserved_visa_type')
            ->distinct()
            ->orderBy('reserved_visa_type')
            ->pluck('reserved_visa_type');

        return response()->json($types);
    }

    /**
     * Mark a payment link as clicked.
     */
    public function markClicked(PaymentLink $paymentLink): JsonResponse
    {
        Gate::authorize('accounts.read');

        $paymentLink->update(['is_clicked' => true]);

        return response()->json($paymentLink);
    }

    /**
     * Return distinct phone numbers that have at least one payment link (scoped to user).
     */
    public function phones(Request $request): JsonResponse
    {
        Gate::authorize('accounts.read');

        $user = $request->user();

        $query = PaymentLink::query()
            ->whereNotNull('account_phone')
            ->whereDate('created_at', now()->toDateString());

        if (! $user->isSuperAdmin()) {
            $query->whereIn('account_phone', $user->visibleAccountPhones());
        }

        $phones = $query->distinct()->pluck('account_phone')->values();

        return response()->json($phones);
    }

    /**
     * Return each phone's most recent payable link created today (scoped to user), for the
     * Accounts page "Pay Now" column.
     */
    public function phoneLinks(Request $request): JsonResponse
    {
        Gate::authorize('accounts.read');

        $user = $request->user();

        $query = PaymentLink::query()
            ->whereNotNull('account_phone')
            ->whereNotNull('gateway_page_url')
            ->whereDate('created_at', now()->toDateString())
            ->orderByDesc('created_at');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('account_phone', $user->visibleAccountPhones());
        }

        $links = $query->get(['account_phone', 'gateway_page_url'])
            ->unique('account_phone')
            ->values()
            ->map(fn (PaymentLink $link) => [
                'phone' => $link->account_phone,
                'url' => $link->gateway_page_url,
            ]);

        return response()->json($links);
    }

    /**
     * Today's reservation per phone, for the invoice button on the accounts page.
     *
     * Kept separate from phoneLinks() rather than folded into it: that endpoint filters on a
     * gateway URL because it drives "Pay Now", and an invoice only needs a reservation — the two
     * sets are not the same rows.
     */
    public function phoneInvoices(Request $request): JsonResponse
    {
        Gate::authorize('accounts.read');

        $user = $request->user();

        $query = PaymentLink::query()
            ->whereNotNull('account_phone')
            ->whereNotNull('reservation_id')
            ->where('is_fake', false)
            ->whereDate('created_at', now()->toDateString())
            ->orderByDesc('created_at');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('account_phone', $user->visibleAccountPhones());
        }

        $invoices = $query->get(['account_phone', 'reservation_id', 'invoice_path'])
            ->unique('account_phone')
            ->values()
            ->map(fn (PaymentLink $link): array => [
                'phone' => $link->account_phone,
                'txr_id' => $link->reservation_id,
                'archived' => $link->invoice_path !== null,
            ]);

        return response()->json($invoices);
    }

    /**
     * Delete a payment link (super admin only).
     */
    public function destroy(PaymentLink $paymentLink): JsonResponse
    {
        Gate::authorize('bot.manage');

        $paymentLink->delete();

        return response()->json(null, 204);
    }

    /**
     * Submit a post-payment redirect URL (portal user action, no specific row).
     *
     * The redirect URL contains a tran_id that equals the slot reservationId, so the URL is
     * routed automatically to whichever payment link carries that reservation_id. The matching
     * bot then picks it up via pollCallback().
     */
    public function submitCallbackUrl(Request $request): JsonResponse
    {
        Gate::authorize('accounts.read');

        $validated = $request->validate([
            'url' => 'required|string|max:4096',
        ]);

        return $this->routeCallbackUrl($validated['url']);
    }

    /**
     * Public REST ingest for a post-payment redirect URL (no auth) — lets the URL be posted
     * from anywhere (script, shortcut, another device) rather than only via the portal modal.
     * Accepts both GET (?url=...) and POST ({"url": "..."}) so it can be hit from a plain
     * browser address bar too. Same tran_id-matching behavior as submitCallbackUrl().
     */
    public function ingestCallbackUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|string|max:4096',
        ]);

        return $this->routeCallbackUrl($validated['url']);
    }

    /**
     * List the most recently submitted redirect URLs (any source: portal modal or public
     * ingest), for display at the bottom of the payment links page.
     */
    public function recentCallbacks(Request $request): JsonResponse
    {
        Gate::authorize('accounts.read');

        $user = $request->user();
        $query = PaymentLink::query()->whereNotNull('callback_url');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('account_phone', $user->visibleAccountPhones());
        }

        $links = $query
            ->orderByDesc('callback_submitted_at')
            ->limit(20)
            ->get(['id', 'account_phone', 'reservation_id', 'callback_url', 'callback_status', 'callback_status_code', 'callback_submitted_at', 'callback_completed_at']);

        return response()->json($links);
    }

    /**
     * Match a redirect URL's tran_id to a payment link and store it for the bot to poll.
     */
    private function routeCallbackUrl(string $url): JsonResponse
    {
        $routed = app(PaymentCallbackRouter::class)->route($url);

        if ($routed['result'] === PaymentCallbackRouter::RESULT_NO_TRAN_ID) {
            return response()->json(['message' => 'No tran_id found in that URL.'], 422);
        }

        if ($routed['result'] === PaymentCallbackRouter::RESULT_NOT_FOUND) {
            return response()->json(['message' => "No payment link matches tran_id {$routed['tran_id']}."], 404);
        }

        $target = $routed['link'];

        return response()->json([
            'id' => $target->id,
            'reservation_id' => $target->reservation_id,
            'account_phone' => $target->account_phone,
            'callback_status' => $target->callback_status,
        ]);
    }

    /**
     * Bot poll (open API): given a reservation_id, return any pending redirect URL.
     */
    public function pollCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reservation_id' => 'required|string|max:128',
        ]);

        $link = PaymentLink::where('reservation_id', $validated['reservation_id'])
            ->latest('id')
            ->first();

        if ($link === null) {
            return response()->json([
                'reservation_id' => $validated['reservation_id'],
                'callback_url' => null,
                'callback_status' => null,
            ]);
        }

        return response()->json([
            'reservation_id' => $link->reservation_id,
            'callback_url' => $link->callback_url,
            'callback_status' => $link->callback_status,
        ]);
    }

    /**
     * Bot reports the final callback GET result (open API).
     */
    public function reportCallbackResult(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reservation_id' => 'required|string|max:128',
            'status' => 'required|in:success,failed',
            'status_code' => 'nullable|integer',
            'response' => 'nullable|string|max:65535',
        ]);

        $link = PaymentLink::where('reservation_id', $validated['reservation_id'])
            ->latest('id')
            ->first();

        if ($link === null) {
            return response()->json(['message' => 'No payment link for that reservation_id.'], 404);
        }

        $link->update([
            'callback_status' => $validated['status'],
            'callback_status_code' => $validated['status_code'] ?? null,
            'callback_response' => $validated['response'] ?? null,
            'callback_completed_at' => now(),
        ]);

        // The bot's `status` only says IVAC accepted the callback — it reports every 3xx as
        // success, decline included. IVAC's redirect target is the actual verdict, so the Payment
        // Status column is set from that instead of waiting for someone to judge the row by hand.
        $verdict = $link->ivacVerdict();

        if ($verdict !== null && $link->review_status === 'unread') {
            $link->update(['review_status' => $verdict]);
        }

        // Bank the invoice while the paying account's session is still alive; it only lasts 899s
        // from sign-in, and IVAC will not hand the PDF to any other account's token.
        if ($verdict === 'succeeded' && ! $link->hasStoredInvoice()) {
            FetchInvoiceJob::dispatch($link->id);
        }

        return response()->json(['ok' => true, 'payment_status' => $link->review_status]);
    }

    /**
     * Download all invoices for the current filter as a ZIP file.
     * Fetches each PDF concurrently via curl_multi and packages them into a single archive.
     */
    public function invoicesZip(Request $request, InvoiceArchive $archive): StreamedResponse|JsonResponse
    {
        Gate::authorize('accounts.read');

        $validated = $request->validate([
            'from_date' => 'nullable|date_format:Y-m-d',
            'to_date' => 'nullable|date_format:Y-m-d',
            'phone' => 'nullable|string|max:32',
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'bypass_all' => 'nullable|boolean',
        ]);

        $user = $request->user();

        // Every real reservation is attempted, not just the ones whose callback came back
        // 'success'. That flag reports only that IVAC accepted our callback GET, so scoping the
        // ZIP to it silently dropped invoices for payments that had in fact gone through. A row
        // IVAC holds nothing for costs one failed fetch and is listed in `failures`.
        $query = PaymentLink::query()
            ->where('is_fake', false)
            ->whereNotNull('reservation_id');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('account_phone', $user->visibleAccountPhones());
        }

        if (! empty($validated['from_date'])) {
            $query->whereDate('created_at', '>=', $validated['from_date']);
        }
        if (! empty($validated['to_date'])) {
            $query->whereDate('created_at', '<=', $validated['to_date']);
        }
        if (! empty($validated['phone'])) {
            $query->where('account_phone', 'like', '%'.$validated['phone'].'%');
        }

        $links = $query->orderBy('created_at')->get();

        if ($links->isEmpty()) {
            return response()->json(['message' => 'No completed invoices found for the selected filters.'], 404);
        }

        // Build ZIP in a temp file.
        $tmpZip = tempnam(sys_get_temp_dir(), 'ivac_invoices_').'.zip';
        $zip = new \ZipArchive;
        $zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $failures = [];

        // Anything banked at payment time is read straight off disk — no IVAC call, no session
        // needed, and no dependence on an account that signed in weeks ago.
        [$stored, $missing] = $links->partition(fn (PaymentLink $link): bool => $link->hasStoredInvoice());

        foreach ($stored as $link) {
            $body = Storage::disk('local')->get($link->invoice_path);

            if (is_string($body) && $body !== '') {
                $zip->addFromString("invoice_{$link->account_phone}_{$link->reservation_id}.pdf", $body);
            }
        }

        if ($missing->isNotEmpty()) {
            $overrideJwt = $request->header('X-Invoice-Token') ?: null;

            // Cloudflare 403s libcurl on its TLS fingerprint, so every fetch goes through the
            // cloudscraper helper — the same path the single-invoice download uses. That is one
            // subprocess per invoice rather than one curl handle, hence the bounded pool.
            $results = $this->fetchInvoicesConcurrently(
                $missing->map(fn (PaymentLink $link): array => [
                    'txrId' => (string) $link->reservation_id,
                    'phone' => $link->account_phone,
                ])->values()->all(),
                Setting::instance()->captcha_bd_proxy_url ?: null,
                $overrideJwt,
            );

            $byReservation = $missing->keyBy('reservation_id');

            foreach ($results as $result) {
                if (! $result['is_pdf']) {
                    $failures[] = $result['txrId'].': '.$result['error'];

                    continue;
                }

                $zip->addFromString("invoice_{$result['phone']}_{$result['txrId']}.pdf", $result['body']);

                // Bank it so this link never costs an IVAC round trip again.
                $link = $byReservation->get($result['txrId']);
                if ($link !== null) {
                    $archive->store($link, $result['body']);
                }
            }
        }

        $added = $zip->count();
        $zip->close();

        if ($added === 0) {
            @unlink($tmpZip);

            // The old message blamed a bypass IP, a mechanism retired in April — so a genuine
            // auth or IVAC-side failure sent everyone hunting for a setting that no longer exists.
            return response()->json([
                'message' => 'None of the '.$links->count().' invoice(s) could be produced — '
                    .'none was archived, and IVAC refused every live fetch.',
                'failures' => array_slice($failures, 0, 10),
            ], 502);
        }

        $zipContents = file_get_contents($tmpZip);
        @unlink($tmpZip);

        if ($zipContents === false) {
            return response()->json([
                'message' => 'Failed to read the generated zip file.',
            ], 500);
        }

        $zipName = 'invoices-'.($validated['from_date'] ?? now()->toDateString()).'.zip';

        return response()->stream(function () use ($zipContents) {
            echo $zipContents;
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$zipName.'"',
            'Content-Length' => strlen($zipContents),
        ]);
    }

    /**
     * Download a reservation's IVAC invoice PDF.
     *
     * Served from the archive whenever a copy was banked at payment time. Only a link with no
     * stored copy reaches IVAC, and a successful fetch is cached on the way out — so an invoice is
     * pulled from IVAC at most once, and stays downloadable long after the account's session has
     * expired and IVAC would refuse it.
     */
    public function invoiceDownload(Request $request, InvoiceArchive $archive): StreamedResponse|JsonResponse
    {
        Gate::authorize('accounts.read');

        $validated = $request->validate([
            'txrId' => 'required|string|max:128',
            'bypass_ip_id' => 'nullable|integer|exists:bypass_ips,id',
            'bypass_all' => 'nullable|boolean',
        ]);

        $txrId = $validated['txrId'];
        $link = PaymentLink::where('reservation_id', $txrId)->latest('id')->first();

        if ($link === null) {
            return response()->json(['message' => "No payment link found for txrId {$txrId}."], 404);
        }

        $result = $archive->get($link);

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 502);
        }

        $body = $result['body'];

        return response()->stream(function () use ($body) {
            echo $body;
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="invoice-'.$txrId.'.pdf"',
            'Content-Length' => strlen($body),
            'X-Invoice-Source' => $result['from_storage'] ? 'storage' : 'ivac',
        ]);
    }

    /**
     * Fetch an invoice through Cloudflare via the cloudscraper helper, tunnelled through the
     * BD proxy. Returns null when the helper could not run; otherwise the IVAC response.
     * $xToken is the raw Cloudflare Turnstile token sent in the x-token header, when present.
     *
     * @return array{status: int, is_pdf: bool, body: string}|null
     */
    private function fetchInvoiceViaCloudscraper(string $url, string $jwtToken, ?string $proxy, ?string $xToken = null): ?array
    {
        $scriptPath = base_path('app/Scripts/fetch_invoice.py');
        if (! file_exists($scriptPath)) {
            return null;
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'ivac_invoice_');
        if ($outputPath === false) {
            return null;
        }

        try {
            $process = new Process(['python3', $scriptPath, $outputPath]);
            $process->setTimeout(60);
            $process->setInput(json_encode(['url' => $url, 'jwt' => $jwtToken, 'proxy' => $proxy, 'x_token' => $xToken]));
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $meta = json_decode(trim($process->getOutput()), true);
            if (! is_array($meta) || ! isset($meta['status'])) {
                return null;
            }

            $body = is_file($outputPath) ? (file_get_contents($outputPath) ?: '') : '';
            $contentType = strtolower((string) ($meta['content_type'] ?? ''));
            $isPdf = (int) $meta['status'] === 200
                && $body !== ''
                && (str_contains($contentType, 'pdf') || str_starts_with($body, '%PDF'));

            return ['status' => (int) $meta['status'], 'is_pdf' => $isPdf, 'body' => $body];
        } finally {
            @unlink($outputPath);
        }
    }

    /** Pick the longest-lived valid JWT from any account session. */
    private function resolveAnyJwt(): ?string
    {
        return AccountSession::where('jwt_expires_at', '>', now())
            ->orderByDesc('jwt_expires_at')
            ->value('jwt_token');
    }

    /**
     * The JWT to fetch one account's invoice with.
     *
     * IVAC scopes an invoice to the account that owns the reservation: asking with a *valid* token
     * belonging to somebody else does not 401, it answers 500 with an incident ID, which reads like
     * an IVAC outage rather than the ownership mismatch it is. So the owner's own session is always
     * preferred, and any-valid-token stays only as a fallback for accounts with no live session.
     */
    private function jwtForPhone(?string $phone): ?string
    {
        if ($phone !== null && $phone !== '') {
            $own = AccountSession::where('phone', $phone)
                ->where('jwt_expires_at', '>', now())
                ->value('jwt_token');

            if ($own !== null) {
                return $own;
            }
        }

        return $this->resolveAnyJwt();
    }

    /**
     * How many cloudscraper subprocesses may run at once.
     *
     * Each invoice costs its own Python process and Cloudflare solve, so an unbounded fan-out over a
     * wide date range would fork hundreds of them at once. Five keeps a large range quick while
     * staying well inside the box's memory.
     */
    private const INVOICE_FETCH_POOL = 5;

    /**
     * Fetch many invoices through cloudscraper, at most INVOICE_FETCH_POOL at a time.
     *
     * @param  list<array{txrId: string, phone: string|null}>  $targets
     * @return list<array{txrId: string, phone: string|null, is_pdf: bool, body: string, error: string|null}>
     */
    private function fetchInvoicesConcurrently(array $targets, ?string $proxy, ?string $overrideJwt): array
    {
        $scriptPath = base_path('app/Scripts/fetch_invoice.py');

        if (! file_exists($scriptPath)) {
            return array_map(
                fn (array $t): array => [...$t, 'is_pdf' => false, 'body' => '', 'error' => 'The invoice fetcher script is missing from the server.'],
                $targets,
            );
        }

        $queue = array_values($targets);
        $running = [];
        $results = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < self::INVOICE_FETCH_POOL) {
                $target = array_shift($queue);
                $started = $this->startInvoiceFetch($scriptPath, $target, $proxy, $overrideJwt);

                // A target with no usable token never starts a process; record it and move on.
                // Named precisely, because "no token" and "no script" used to read the same and a
                // sign-in fixes only one of them.
                if ($started === null) {
                    $results[] = [...$target, 'is_pdf' => false, 'body' => '', 'error' => 'No unexpired IVAC session token is available — sign in to any account first.'];

                    continue;
                }

                $running[] = $started;
            }

            foreach ($running as $i => $job) {
                if ($job['process']->isRunning()) {
                    continue;
                }

                $results[] = $this->collectInvoiceFetch($job);
                unset($running[$i]);
            }
            $running = array_values($running);

            if ($running !== []) {
                usleep(50_000);
            }
        }

        return $results;
    }

    /**
     * Launch one cloudscraper fetch without blocking on it.
     *
     * @param  array{txrId: string, phone: string|null}  $target
     * @return array{txrId: string, phone: string|null, process: Process, output: string}|null
     */
    private function startInvoiceFetch(string $scriptPath, array $target, ?string $proxy, ?string $overrideJwt): ?array
    {
        $jwt = $overrideJwt ?: $this->jwtForPhone($target['phone']);

        if ($jwt === null) {
            return null;
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'ivac_invoice_');
        if ($outputPath === false) {
            return null;
        }

        $url = 'https://api.ivacbd.com/iams/api/v1/invoice/download?txrId='.urlencode($target['txrId']);
        $process = new Process(['python3', $scriptPath, $outputPath]);
        $process->setTimeout(60);
        // IVAC gates the download on a raw Turnstile token, and a token is single-use — so each
        // invoice in the batch needs its own, not one shared across the pool.
        $process->setInput(json_encode([
            'url' => $url,
            'jwt' => $jwt,
            'proxy' => $proxy,
            'x_token' => app(RawCaptchaTokenProvider::class)->mint(),
        ]));
        $process->start();

        return [...$target, 'process' => $process, 'output' => $outputPath];
    }

    /**
     * Read one finished fetch and clean up its temp file.
     *
     * @param  array{txrId: string, phone: string|null, process: Process, output: string}  $job
     * @return array{txrId: string, phone: string|null, is_pdf: bool, body: string, error: string|null}
     */
    private function collectInvoiceFetch(array $job): array
    {
        $base = ['txrId' => $job['txrId'], 'phone' => $job['phone']];

        try {
            if (! $job['process']->isSuccessful()) {
                return [...$base, 'is_pdf' => false, 'body' => '', 'error' => 'The invoice fetcher could not run.'];
            }

            $meta = json_decode(trim($job['process']->getOutput()), true);
            if (! is_array($meta) || ! isset($meta['status'])) {
                return [...$base, 'is_pdf' => false, 'body' => '', 'error' => 'The invoice fetcher returned no status.'];
            }

            $body = is_file($job['output']) ? (file_get_contents($job['output']) ?: '') : '';
            $status = (int) $meta['status'];
            $isPdf = $status === 200
                && $body !== ''
                && (str_contains(strtolower((string) ($meta['content_type'] ?? '')), 'pdf') || str_starts_with($body, '%PDF'));

            if ($isPdf) {
                return [...$base, 'is_pdf' => true, 'body' => $body, 'error' => null];
            }

            $decoded = json_decode($body, true);
            $message = (is_array($decoded) && is_string($decoded['message'] ?? null))
                ? $decoded['message']
                : "IVAC returned status {$status}.";

            return [...$base, 'is_pdf' => false, 'body' => '', 'error' => $message];
        } finally {
            @unlink($job['output']);
        }
    }
}
