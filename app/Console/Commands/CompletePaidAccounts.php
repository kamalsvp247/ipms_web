<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Apply the paid-means-completed rule to accounts whose payment predates it.
 *
 * PaymentLink::completeAccount() keeps every new verdict in step from here on; this is the one-off
 * catch-up for links already marked paid, and is safe to replay — an account that is no longer
 * running is skipped rather than reset.
 */
class CompletePaidAccounts extends Command
{
    protected $signature = 'accounts:complete-paid
                            {--dry-run : List the accounts that would change without writing}';

    protected $description = 'Mark every running account that has a paid payment link as completed';

    public function handle(): int
    {
        $accounts = Account::where('status', 'running')
            ->whereHas('paymentLinks', fn (Builder $link) => $link
                ->where('is_fake', false)
                ->where('review_status', 'succeeded'))
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No running accounts have a paid payment link.');

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $this->line("#{$account->id}  {$account->phone}");

            if (! $this->option('dry-run')) {
                $account->update(['status' => 'completed']);
            }
        }

        $verb = $this->option('dry-run') ? 'Would mark' : 'Marked';
        $this->info("{$verb} {$accounts->count()} account(s) completed.");

        return self::SUCCESS;
    }
}
