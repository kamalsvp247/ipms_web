<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('local_ips', function (Blueprint $table) {
            $table->unsignedInteger('ping_iodp_ms')->nullable()->after('last_ping_ms');
            $table->unsignedInteger('ping_cf_ms')->nullable()->after('ping_iodp_ms');
        });
    }

    public function down(): void
    {
        Schema::table('local_ips', function (Blueprint $table) {
            $table->dropColumn(['ping_iodp_ms', 'ping_cf_ms']);
        });
    }
};
