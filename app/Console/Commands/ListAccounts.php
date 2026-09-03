<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ListAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:list-accounts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $accounts = \App\Models\Account::select('id', 'phone', 'email', 'status')->get();

        $this->table(
            ['ID', 'Phone', 'Email', 'Status'],
            $accounts->map(fn($a) => [$a->id, $a->phone, $a->email, $a->status])->toArray()
        );
    }
}
