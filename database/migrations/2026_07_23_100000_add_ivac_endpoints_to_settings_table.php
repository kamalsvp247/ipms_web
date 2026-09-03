<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IVAC bakes its API endpoint paths (and two request headers) into its frontend bundle and
     * rotates them on redeploy (/auth/v12-sign-in -> /auth/v23-sign-in, upload_file -> upload_file_v23,
     * ...). This JSON bag is kept in sync from the bundle by CaptchaAlgorithmService::syncEndpoints()
     * and shipped to the bot via /api/config, so a rotation needs no Java edit or JAR rebuild. The
     * reserveSlot/payment values are templates — their UUIDs stay synced separately (reserve_slot_id,
     * payment_config_id) and are substituted by the bot at call time.
     *
     * @return array<string, string>
     */
    private function defaults(): array
    {
        return [
            'signin' => '/auth/v23-sign-in',
            'sendOtp' => '/forgot-password/sendOtp',
            'verifyOtp' => '/otp/verifySigninOtp',
            'uploadFile' => '/file/upload_file_v23',
            'bookingConfig' => '/appointment/appointment-booking-config',
            'getBookingConfig' => '/appointment/get-booking-config',
            'reserveSlot' => '/slots/{reserveSlotId}/reserve-slot',
            'payment' => '/payment/{paymentConfigId}/dg-epay/initiate',
            'signinNavState' => '80d51dc5-af20-46fa-a7bb-e6a8f3f80065',
            'uploadRuntimeState' => 'v1.5a4c8831.9a53.47ed.b579.042a2c0cee5a',
        ];
    }

    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->json('ivac_endpoints')->nullable()->after('reserve_request_meta');
        });

        DB::table('settings')->update(['ivac_endpoints' => json_encode($this->defaults())]);
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('ivac_endpoints');
        });
    }
};
