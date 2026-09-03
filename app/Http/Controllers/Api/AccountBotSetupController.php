<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AgentSlot;
use App\Services\Pdf\AccountPdfProfileSync;
use App\Support\IvacBookingCities;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Slot-authenticated endpoints the Java bot uses to prepare an account:
 * fetching the applicant PDFs (kept out of /api/config) and reporting the
 * one-time PDF-upload / booking-config setup flags back to the portal.
 */
class AccountBotSetupController extends Controller
{
    private function resolveSlot(Request $request): ?AgentSlot
    {
        $apiKey = $request->bearerToken();

        return $apiKey ? AgentSlot::where('api_key', $apiKey)->first() : null;
    }

    /**
     * GET /api/accounts/{account}/pdfs
     * Returns the account's PDFs (name, base64, is_primary) for the owning slot only.
     */
    public function pdfs(Request $request, Account $account): JsonResponse
    {
        $slot = $this->resolveSlot($request);

        if ($slot === null) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        if ($account->agent_slot_id !== $slot->id) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return response()->json(['pdfs' => $this->pdfPayload($account)]);
    }

    /**
     * The account's documents in the shape the bot uploads them in, each carrying the optimized copy
     * when one exists so the upload to IVAC moves as few bytes as possible.
     *
     * @return list<array{name: string|null, base64: string, is_primary: bool}>
     */
    private function pdfPayload(Account $account): array
    {
        return $account->pdfs()->orderBy('id')->get()
            ->map(fn ($pdf): array => [
                'name' => $pdf->name,
                'base64' => $pdf->deliverable_base64,
                'is_primary' => (bool) $pdf->is_primary,
            ])->all();
    }

    /**
     * POST /api/accounts/pdf-profile-sync
     * Body: { phone, profile: { given_name, surname, passport, nid, phone, email } }
     *
     * IVAC rejected the primary PDF because the form disagrees with the account. The bot has read
     * GET /profile and sends it here; the form is rewritten to state it, and the corrected documents
     * come straight back so the bot can re-upload without a second round trip.
     *
     * "phone" is the account this is for; "profile.phone" is the number to write onto the form. They
     * are normally the same value, but they are kept apart deliberately — conflating a lookup key with
     * a payload value is exactly the class of bug that made IVAC's own surName/surname asymmetry a
     * silent no-op.
     */
    public function pdfProfileSync(Request $request, AccountPdfProfileSync $sync): JsonResponse
    {
        $slot = $this->resolveSlot($request);

        if ($slot === null) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        $data = $request->validate([
            'phone' => 'required|string',
            'profile' => 'required|array',
            'profile.given_name' => 'nullable|string|max:100',
            'profile.surname' => 'nullable|string|max:100',
            'profile.passport' => 'nullable|string|max:50',
            'profile.nid' => 'nullable|string|max:50',
            'profile.phone' => 'nullable|string|max:30',
            'profile.email' => 'nullable|string|max:150',
        ]);

        $account = Account::where('phone', $data['phone'])
            ->where('agent_slot_id', $slot->id)
            ->first();

        if ($account === null) {
            return response()->json(['error' => 'account_not_found'], 404);
        }

        $result = $sync->sync($account, $data['profile']);

        if ($result['status'] === 'no_primary') {
            return response()->json(['error' => 'no_primary_pdf'], 422);
        }

        if ($result['status'] === 'edit_failed') {
            return response()->json(['error' => 'pdf_edit_failed'], 422);
        }

        // The edit ran but at least one value never landed on the form — the stored document is
        // untouched. Naming the fields is what tells an operator whether the form's layout has moved.
        if ($result['status'] === 'not_applied') {
            return response()->json([
                'error' => 'fields_not_applied',
                'fields' => $result['unapplied'],
            ], 422);
        }

        return response()->json([
            'phone' => $account->phone,
            'changed' => $result['status'] === 'updated',
            'pdfs' => $this->pdfPayload($account),
        ]);
    }

    /**
     * POST /api/accounts/appointment-dates
     * Body: { phone, appointment_dates: ["2026-07-26", ...] }
     * Replaces the operator's guessed From/To range with the dates IVAC itself reports as
     * available for the account (from GET /appointment/get-booking-config), so the next window
     * races on the real list. Stored sorted + de-duplicated: the accounts UI reads the first and
     * last entries as the From/To range.
     */
    public function appointmentDates(Request $request): JsonResponse
    {
        $slot = $this->resolveSlot($request);

        if ($slot === null) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        $data = $request->validate([
            'phone' => 'required|string',
            'appointment_dates' => 'required|array|min:1',
            'appointment_dates.*' => 'required|date',
        ]);

        $account = Account::where('phone', $data['phone'])
            ->where('agent_slot_id', $slot->id)
            ->first();

        if ($account === null) {
            return response()->json(['error' => 'account_not_found'], 404);
        }

        $dates = collect($data['appointment_dates'])
            ->map(fn (string $date): string => Carbon::parse($date)->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->all();

        $account->update(['appointment_dates' => $dates]);

        return response()->json([
            'phone' => $account->phone,
            'appointment_dates' => $dates,
        ]);
    }

    /**
     * Makes the named document the account's only primary. A no-op when the name matches no row, so
     * a stale bot cannot clear the flag from every document and leave the account with none.
     */
    private function repointPrimaryPdf(Account $account, string $pdfName): void
    {
        $target = $account->pdfs()->where('name', $pdfName)->first();

        if ($target === null || $target->is_primary) {
            return;
        }

        DB::transaction(function () use ($account, $target): void {
            $account->pdfs()->where('id', '!=', $target->id)->update(['is_primary' => false]);
            $target->update(['is_primary' => true]);

            // The IVAC-side chain was built around the wrong applicant, so it has to be re-run.
            $account->update([
                'pdf_uploaded' => false,
                'pdf_uploaded_date' => null,
                'booking_configured' => false,
                'booking_configured_date' => null,
            ]);
        });
    }

    /**
     * POST /api/accounts/setup-state
     * Body: { phone, pdf_uploaded?, booking_configured?, ivac_profile_name?, primary_pdf_name?, booking_city? }
     * Persists the one-time setup flags so a bot restart does not repeat the work.
     */
    public function setupState(Request $request): JsonResponse
    {
        $slot = $this->resolveSlot($request);

        if ($slot === null) {
            return response()->json(['error' => 'invalid_api_key'], 401);
        }

        $data = $request->validate([
            'phone' => 'required|string',
            'pdf_uploaded' => 'nullable|boolean',
            'booking_configured' => 'nullable|boolean',
            'ivac_profile_name' => 'nullable|string|max:255',
            'primary_pdf_name' => 'nullable|string|max:255',
            'booking_city' => ['nullable', Rule::in(IvacBookingCities::keys())],
        ]);

        $account = Account::where('phone', $data['phone'])
            ->where('agent_slot_id', $slot->id)
            ->first();

        if ($account === null) {
            return response()->json(['error' => 'account_not_found'], 404);
        }

        // Stamp the Dhaka date each step completes on, so /api/config reports it as done only for
        // today's window. IVAC wipes the appointment/PDFs/booking-config after the window, so a
        // stale flag from a previous day must not skip today's mandatory re-run.
        $today = now('Asia/Dhaka')->toDateString();

        $update = [];
        if (array_key_exists('pdf_uploaded', $data) && $data['pdf_uploaded'] !== null) {
            $update['pdf_uploaded'] = (bool) $data['pdf_uploaded'];
            $update['pdf_uploaded_date'] = $data['pdf_uploaded'] ? $today : null;
        }
        if (array_key_exists('booking_configured', $data) && $data['booking_configured'] !== null) {
            $update['booking_configured'] = (bool) $data['booking_configured'];
            $update['booking_configured_date'] = $data['booking_configured'] ? $today : null;
        }

        // IVAC rejected the operator's centre with "Invalid High Commission." and the bot's rotation
        // found one it accepts. Store it so the portal shows where the account is really booked and
        // the next window starts on that centre instead of walking the rotation again. Deliberately
        // does NOT clear booking_configured the way an operator edit does — the bot has just
        // configured IVAC with this very city.
        if (! empty($data['booking_city'])) {
            $update['booking_city'] = $data['booking_city'];
        }

        // The name IVAC holds for the account, reported after the bot resolves a mismatch on the
        // primary PDF by rewriting the form to match it. Recorded so an operator can see what the
        // account is actually registered as; the repeat guard itself lives in the bot.
        if (! empty($data['ivac_profile_name'])) {
            $update['ivac_profile_name'] = $data['ivac_profile_name'];
            $update['profile_name_fixed_at'] = now();
        }

        if (! empty($update)) {
            $account->update($update);
        }

        // The configured primary document turned out to be a different applicant's form, and IVAC
        // confirmed which of the account's other documents belongs to the holder. Move the flag so
        // the next run uploads the right one first; the upload order is what IVAC enforces.
        if (! empty($data['primary_pdf_name'])) {
            $this->repointPrimaryPdf($account, $data['primary_pdf_name']);
        }

        return response()->json([
            'phone' => $account->phone,
            'pdf_uploaded' => (bool) $account->pdf_uploaded,
            'booking_configured' => (bool) $account->booking_configured,
            'booking_city' => $account->booking_city,
            'ivac_profile_name' => $account->ivac_profile_name,
            'primary_pdf_name' => $account->pdfs()->where('is_primary', true)->value('name'),
        ]);
    }
}
