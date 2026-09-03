<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Delete older duplicate rows, keeping the highest id per IP
        // SQLite-compatible: delete rows whose id is not the max for that IP
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('
                DELETE FROM ip_scan_results
                WHERE id NOT IN (
                    SELECT MAX(id) FROM ip_scan_results GROUP BY ip
                )
            ');
        } else {
            DB::statement('
                DELETE r FROM ip_scan_results r
                INNER JOIN (
                    SELECT ip, MAX(id) AS keep_id
                    FROM ip_scan_results
                    GROUP BY ip
                    HAVING COUNT(*) > 1
                ) dup ON r.ip = dup.ip AND r.id < dup.keep_id
            ');
        }

        Schema::table('ip_scan_results', function (Blueprint $table) {
            $table->unique('ip');
        });
    }

    public function down(): void
    {
        Schema::table('ip_scan_results', function (Blueprint $table) {
            $table->dropUnique(['ip']);
        });
    }
};
