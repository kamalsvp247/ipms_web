<?php

namespace App\Console\Commands;

use App\Models\BypassIp;
use App\Services\BypassIpScanner;
use Illuminate\Console\Command;

class ScanAwsIpRanges extends Command
{
    protected $signature = 'bypass-ips:scan-subnets
                            {--dry-run : List found IPs without saving to the database}';

    protected $description = 'Scan /24 subnets of existing bypass IPs for new IVAC origin IPs';

    public function handle(BypassIpScanner $scanner): int
    {
        $existingIps = BypassIp::pluck('ip')->toArray();

        if (empty($existingIps)) {
            $this->warn('No existing bypass IPs — nothing to derive subnets from.');

            return self::SUCCESS;
        }

        ['subnets' => $subnets, 'candidates' => $candidates] = $scanner->buildCandidates($existingIps);

        $this->info('Subnets: '.implode(', ', array_map(fn ($s) => "{$s}.0/24", $subnets)));
        $this->info('Probing '.count($candidates).' candidate IPs…');

        $found = $scanner->probe($candidates);

        if (empty($found)) {
            $this->info('No new IPs found.');

            return self::SUCCESS;
        }

        $this->info(count($found).' new IP(s) found:');

        foreach ($found as $result) {
            $this->line("  {$result['ip']}  [{$result['response_time_ms']}ms]  {$result['response_message']}");

            if (! $this->option('dry-run')) {
                BypassIp::firstOrCreate(
                    ['ip' => $result['ip']],
                    ['label' => $result['label']]
                );
            }
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing saved.');
        } else {
            $this->info('Saved to bypass_ips table.');
        }

        return self::SUCCESS;
    }
}
