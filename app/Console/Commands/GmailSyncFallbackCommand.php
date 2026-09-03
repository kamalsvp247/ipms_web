<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GmailSyncFallbackCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gmail:sync-fallback';

    protected $description = 'Force-sync Gmail messages for all connected accounts (fallback for missed push notifications).';

    public function __construct(
        private readonly \App\Services\GmailSyncService $gmailSyncService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $accounts = \App\Models\GoogleAccount::all();

        foreach ($accounts as $account) {
            try {
                $this->gmailSyncService->fallbackSync($account);
                $this->info("Synced {$account->email_address}");
            } catch (\Exception $e) {
                $this->error("Sync failed for {$account->email_address}: {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }
}
