<?php

namespace Database\Seeders;

use App\Models\BypassIp;
use Illuminate\Database\Seeder;

class BypassIpSeeder extends Seeder
{
    public function run(): void
    {
        // Default CF bypass IP — used for any slot that has no bypass IP assigned.
        BypassIp::updateOrCreate(
            ['ip' => '65.1.89.220'],
            ['label' => 'CF Bypass — 65 series (default)', 'is_default' => true]
        );

        // Secondary CF bypass IP.
        BypassIp::updateOrCreate(
            ['ip' => '3.7.190.55'],
            ['label' => 'CF Bypass — 3.7 series', 'is_default' => false]
        );
    }
}
