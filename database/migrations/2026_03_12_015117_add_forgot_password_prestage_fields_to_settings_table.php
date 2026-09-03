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
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('use_forgot_password_prestage')->default(false)->after('resend_otp_lead_seconds');
            $table->unsignedInteger('forgot_password_lead_seconds')->default(120)->after('use_forgot_password_prestage');
            $table->unsignedInteger('signin_retry_lead_seconds')->default(60)->after('forgot_password_lead_seconds');
            $table->unsignedInteger('prestage_otp_timeout_ms')->default(30000)->after('signin_retry_lead_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['use_forgot_password_prestage', 'forgot_password_lead_seconds', 'signin_retry_lead_seconds', 'prestage_otp_timeout_ms']);
        });
    }
};
