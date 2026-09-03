<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE ip_scan_sessions MODIFY COLUMN type ENUM('ipv4', 'ipv6', 'censys') NOT NULL DEFAULT 'ipv4'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE ip_scan_sessions MODIFY COLUMN type ENUM('ipv4', 'ipv6') NOT NULL DEFAULT 'ipv4'");
        }
    }
};
