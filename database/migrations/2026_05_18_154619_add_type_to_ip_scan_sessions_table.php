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
        Schema::table('ip_scan_sessions', function (Blueprint $table) {
            $table->enum('type', ['ipv4', 'ipv6'])->default('ipv4')->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('ip_scan_sessions', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
