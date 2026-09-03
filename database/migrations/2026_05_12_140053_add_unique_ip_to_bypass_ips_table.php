<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the highest id per duplicate IP, delete the rest
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('
                DELETE FROM bypass_ips
                WHERE id NOT IN (
                    SELECT MAX(id) FROM bypass_ips GROUP BY ip
                )
            ');
        } else {
            DB::statement('
                DELETE b FROM bypass_ips b
                INNER JOIN (
                    SELECT ip, MAX(id) AS keep_id
                    FROM bypass_ips
                    GROUP BY ip
                    HAVING COUNT(*) > 1
                ) dup ON b.ip = dup.ip AND b.id < dup.keep_id
            ');
        }

        Schema::table('bypass_ips', function (Blueprint $table) {
            $table->unique('ip');
        });
    }

    public function down(): void
    {
        Schema::table('bypass_ips', function (Blueprint $table) {
            $table->dropUnique(['ip']);
        });
    }
};
