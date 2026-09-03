<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE ip_scan_sessions MODIFY COLUMN type ENUM('ipv4', 'ipv6', 'censys', 'frontend') NOT NULL DEFAULT 'ipv4'");
        }

        Schema::table('ip_scan_sessions', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('ip_scan_sessions', function (Blueprint $table) {
            $table->dropColumn('meta');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE ip_scan_sessions MODIFY COLUMN type ENUM('ipv4', 'ipv6', 'censys') NOT NULL DEFAULT 'ipv4'");
        }
    }
};
