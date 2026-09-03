<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountSession;
use App\Models\PaymentLink;
use App\Models\User;
use App\Support\IvacBookingCities;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeedFakePaymentLinks extends Command
{
    /**
     * Leading base64 characters shared by every real dg-epay checkout blob — the gateway encrypts a
     * constant header, so the ciphertext prefix is identical across links and a fake has to carry it.
     */
    private const DGEPAY_DATA_PREFIX = 'ngtso1MSQhZi2D6q4pBZz';

    /**
     * Length of a real dg-epay `data` blob (constant across every observed link, single '=' pad).
     */
    private const DGEPAY_DATA_LENGTH = 428;

    /**
     * Every bulk-seeded link lands inside this span today, mimicking a short burst of bookings
     * rather than a spread across the whole day.
     */
    private const BULK_WINDOW_MINUTES = 6;

    /** Share of bulk-seeded links marked "succeeded" rather than "declined". */
    private const BULK_SUCCEEDED_RATIO = 0.8;

    /**
     * Where the IDs of bulk-seeded synthetic accounts are recorded so --clear can find them again.
     * Nothing about a bulk-seeded account is visibly marked (no tag, no flag) — this registry is the
     * only record, so the accounts blend in with real ones everywhere in the UI.
     */
    private const REGISTRY_PATH = 'fake-payment-link-accounts.json';

    /** Visa types observed on real account_sessions, for realistic bulk-seeded values. */
    private const VISA_TYPES = ['TOURIST', 'MEDICAL', 'MISCELLANEOUS'];

    /** Applicant counts weighted roughly like the real distribution (4 and 2 are most common). */
    private const APPLICANT_WEIGHTS = [1, 2, 2, 3, 4, 4, 4];

    /** Share of bulk-seeded accounts given a tag (copied from a real account) rather than none. */
    private const TAGGED_RATIO = 0.75;

    protected $signature = 'payment-links:seed-fakes
        {--clear : Delete all fake links instead of seeding}
        {--bulk= : Create N fake links for brand-new synthetic accounts, dated today, instead of cloning real ones}';

    protected $description = 'For every date that has real payment links, add the same number of fake "declined" copies (flagged is_fake so they can be purged later).';

    public function handle(): int
    {
        if ($this->option('clear')) {
            $deletedLinks = PaymentLink::where('is_fake', true)->delete();

            $accountIds = Storage::exists(self::REGISTRY_PATH)
                ? collect(json_decode(Storage::get(self::REGISTRY_PATH), true) ?? [])
                : collect();
            $deletedSessions = AccountSession::whereIn('account_id', $accountIds)->delete();
            $deletedAccounts = Account::whereIn('id', $accountIds)->delete();
            Storage::delete(self::REGISTRY_PATH);

            $this->info("Deleted {$deletedLinks} fake payment link(s), {$deletedAccounts} fake account(s), {$deletedSessions} fake session(s).");

            return self::SUCCESS;
        }

        if ($bulk = $this->option('bulk')) {
            return $this->seedBulkFakeAccounts((int) $bulk);
        }

        $existingFakes = PaymentLink::where('is_fake', true)->count();
        if ($existingFakes > 0) {
            $this->warn("{$existingFakes} fake link(s) already exist. Run with --clear first to reseed. Aborting.");

            return self::FAILURE;
        }

        $realLinks = PaymentLink::where('is_fake', false)->orderBy('id')->get();
        if ($realLinks->isEmpty()) {
            $this->warn('No real payment links found; nothing to clone.');

            return self::SUCCESS;
        }

        $created = 0;
        foreach ($realLinks as $link) {
            $this->cloneAsFakeDeclined($link);
            $created++;
        }

        $dates = $realLinks->groupBy(fn (PaymentLink $l) => $l->created_at->toDateString())->count();
        $this->info("Created {$created} fake 'declined' link(s) across {$dates} date(s) — each dated date now has double its links.");

        return self::SUCCESS;
    }

    /**
     * Create N fake links for brand-new synthetic phone numbers (not tied to any real account),
     * all dated today and clustered inside a tight window so they read as a single burst.
     */
    private function seedBulkFakeAccounts(int $count): int
    {
        if ($count < 1) {
            $this->error('--bulk must be a positive integer.');

            return self::FAILURE;
        }

        $existingFakes = PaymentLink::where('is_fake', true)->count();
        if ($existingFakes > 0) {
            $this->warn("{$existingFakes} fake link(s) already exist. Run with --clear first to reseed. Aborting.");

            return self::FAILURE;
        }

        $usedPhones = PaymentLink::pluck('account_phone')
            ->merge(Account::pluck('phone'))
            ->filter()
            ->flip();

        $agentIds = User::where('role', User::ROLE_AGENT)->pluck('id');
        if ($agentIds->isEmpty()) {
            $this->warn('No agent users found; falling back to any user for account assignment.');
            $agentIds = User::pluck('id');
        }

        $realTags = Account::whereNotNull('tag')->where('tag', '!=', '')->pluck('tag');

        $windowStart = now()->subMinutes(self::BULK_WINDOW_MINUTES)->timestamp;
        $windowEnd = now()->timestamp;
        $succeededCount = (int) round($count * self::BULK_SUCCEEDED_RATIO);

        $createdAccountIds = [];

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($i = 0; $i < $count; $i++) {
            $phone = $this->uniqueFakePhone($usedPhones);
            $usedPhones[$phone] = true;

            $createdAt = Carbon::createFromTimestamp(random_int($windowStart, $windowEnd));
            $succeeded = $i < $succeededCount;

            $account = new Account([
                'user_id' => $agentIds->random(),
                'phone' => $phone,
                'password' => Str::random(16),
                'tag' => ($realTags->isNotEmpty() && random_int(1, 100) <= self::TAGGED_RATIO * 100) ? $realTags->random() : null,
                'status' => 'completed',
                'booking_city' => collect(IvacBookingCities::keys())->random(),
            ]);
            $account->created_at = $createdAt;
            $account->updated_at = $createdAt;
            $account->save();
            $createdAccountIds[] = $account->id;

            $session = new AccountSession([
                'account_id' => $account->id,
                'phone' => $phone,
                'reserved_visa_type' => collect(self::VISA_TYPES)->random(),
                'reserved_applicants' => collect(self::APPLICANT_WEIGHTS)->random(),
            ]);
            $session->created_at = $createdAt;
            $session->updated_at = $createdAt;
            $session->save();

            $link = new PaymentLink([
                'account_id' => $account->id,
                'account_phone' => $phone,
                'reservation_id' => 'FAKE-'.Str::uuid()->toString(),
                'gateway_page_url' => 'https://checkout.dgepay.net/payment/payment-methods?data='.$this->fakeDgePayData(),
                'status_code' => 201,
                'message' => 'Initiated',
                'success_flag' => true,
                'is_clicked' => (bool) random_int(0, 1),
                'is_fake' => true,
                'review_status' => $succeeded ? 'succeeded' : 'declined',
            ]);

            if ($succeeded) {
                $link->callback_status = 'success';
                $link->callback_status_code = 302;
                $link->callback_submitted_at = $createdAt->copy()->addSeconds(random_int(20, 90));
                $link->callback_completed_at = $link->callback_submitted_at->copy()->addSeconds(random_int(1, 5));
            }

            $link->created_at = $createdAt;
            $link->updated_at = $createdAt;
            $link->save();

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        Storage::put(self::REGISTRY_PATH, json_encode($createdAccountIds));

        $this->info("Created {$count} fake account(s) + payment link(s) with agent/visa type/applicants filled in, dated ".now()->toDateString().' (untagged in the UI — purge anytime with --clear).');

        return self::SUCCESS;
    }

    /**
     * Random Bangladeshi-shaped mobile number in the same "00" + 11-digit stored format used by
     * real accounts, retried until it doesn't collide with an existing account/payment-link phone.
     */
    private function uniqueFakePhone(Collection $usedPhones): string
    {
        do {
            $operator = collect([3, 4, 5, 6, 7, 8, 9])->random();
            $phone = '001'.$operator.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        } while (isset($usedPhones[$phone]));

        return $phone;
    }

    /**
     * Clone a real link into a fake copy marked declined, keeping it on the same date (created_at) and
     * phone so it lands in the same per-date / per-phone group in the UI. The checkout URLs get a fresh
     * distinct token in the same gateway's format as the source link instead of being copied, so the
     * fake is not a duplicate of the real link's URL.
     */
    private function cloneAsFakeDeclined(PaymentLink $link): void
    {
        $fake = $link->replicate([
            'callback_status',
            'callback_status_code',
            'callback_response',
            'callback_submitted_at',
            'callback_completed_at',
        ]);

        $fake->is_fake = true;
        $fake->review_status = 'declined';
        $fake->is_clicked = false;
        $fake->reservation_id = 'FAKE-'.Str::uuid()->toString();
        $fake->gateway_page_url = $link->gateway_page_url !== null
            ? $this->fakeGatewayUrl($link->gateway_page_url)
            : null;
        $fake->redirect_gateway_url = $link->redirect_gateway_url !== null ? $this->fakeSslCommerzRedirectUrl() : null;
        $fake->created_at = $link->created_at;
        $fake->updated_at = $link->created_at;
        $fake->save();
    }

    /**
     * Mint a checkout URL shaped like the gateway the source link came from. dg-epay is the gateway
     * IVAC uses today (`data.webview_url`); SSLCommerz only appears on links predating the switch.
     */
    private function fakeGatewayUrl(string $sourceUrl): string
    {
        if (str_contains($sourceUrl, 'sslcommerz.com')) {
            return 'https://pay.sslcommerz.com/'.bin2hex(random_bytes(20));
        }

        return 'https://checkout.dgepay.net/payment/payment-methods?data='.$this->fakeDgePayData();
    }

    /**
     * Random blob matching a real dg-epay `data` value: the shared ciphertext prefix followed by
     * base64 noise, at the exact observed length and padding.
     */
    private function fakeDgePayData(): string
    {
        $blob = base64_encode(random_bytes(intdiv(self::DGEPAY_DATA_LENGTH, 4) * 3 - 1));

        return self::DGEPAY_DATA_PREFIX.substr($blob, strlen(self::DGEPAY_DATA_PREFIX));
    }

    private function fakeSslCommerzRedirectUrl(): string
    {
        return 'https://securepay.sslcommerz.com/gwprocess/v4/gw.php?Q=REDIRECT&SESSIONKEY='
            .strtoupper(bin2hex(random_bytes(16))).'&cardname=';
    }
}
