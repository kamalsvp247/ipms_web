<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GmailRenewWatchCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gmail:renew-watch';

    protected $description = 'Renew Gmail push watch subscriptions expiring within 48 hours.';

    public function __construct(
        private readonly \App\Services\GmailService $gmailService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $accounts = \App\Models\GoogleAccount::query()
            ->where(function ($q) {
                $q->whereNull('watch_expires_at')
                    ->orWhere('watch_expires_at', '<', now()->addHours(48));
            })
            ->get();

        foreach ($accounts as $account) {
            try {
                $result = $this->gmailService->watchInbox($account);
                $account->update([
                    'history_id' => $result['history_id'],
                    'watch_expires_at' => \Carbon\Carbon::createFromTimestampMs($result['expiration']),
                ]);
                $this->info("Renewed watch for {$account->email_address}");
            } catch (\Exception $e) {
                $this->error("Failed to renew watch for {$account->email_address}: {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }
}
