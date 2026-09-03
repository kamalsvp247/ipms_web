<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_sessions', function (Blueprint $table) {
            $table->json('last_booking_config')->nullable()->after('request_id');
            $table->json('last_file_overview')->nullable()->after('last_booking_config');
        });
    }

    public function down(): void
    {
        Schema::table('account_sessions', function (Blueprint $table) {
            $table->dropColumn(['last_booking_config', 'last_file_overview']);
        });
    }
};
