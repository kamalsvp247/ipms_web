<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks an ingested SMS as a mobile-financial-service payment OTP (bKash/Nagad/Rocket) so the
     * auto-payment driver can consume it without ever colliding with an IVAC sign-in OTP.
     */
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->boolean('is_mfs')->default(false)->after('is_ivacbd');
            $table->index(['phone', 'is_mfs']);
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropIndex(['phone', 'is_mfs']);
            $table->dropColumn('is_mfs');
        });
    }
};
