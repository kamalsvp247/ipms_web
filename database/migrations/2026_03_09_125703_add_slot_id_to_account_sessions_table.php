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
        Schema::table('account_sessions', function (Blueprint $table) {
            $table->string('slot_id', 64)->nullable()->after('otp_verified_server_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_sessions', function (Blueprint $table) {
            $table->dropColumn('slot_id');
        });
    }
};
