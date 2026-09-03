<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'firebase_email_otp_url')) {
                $table->dropColumn('firebase_email_otp_url');
            }
            if (Schema::hasColumn('settings', 'firebase_otp_url')) {
                $table->dropColumn('firebase_otp_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('firebase_otp_url')->nullable();
            $table->string('firebase_email_otp_url')->nullable()->after('firebase_otp_url');
        });
    }
};
