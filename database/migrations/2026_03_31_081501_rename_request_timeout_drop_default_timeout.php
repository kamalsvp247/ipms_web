<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('default_timeout_ms');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->renameColumn('request_timeout_ms', 'slot_probe_delay_ms');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedInteger('default_timeout_ms')->default(30000)->after('firebase_otp_url');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->renameColumn('slot_probe_delay_ms', 'request_timeout_ms');
        });
    }
};
