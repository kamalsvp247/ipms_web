<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Deployment-scoped constants IVAC bakes into its frontend bundle and rotates on
            // redeploy, kept in sync from the bundle by CaptchaAlgorithmService (like reserve_slot_id):
            //   payment_config_id    — the UUID in POST /payment/{id}/dg-epay/initiate
            //   reserve_request_meta — the x-v-request-meta header value on POST .../reserve-slot
            $table->string('payment_config_id')
                ->nullable()
                ->default('f2a2fcd1-4019-4291-ba2c-ea94a60ea54f')
                ->after('reserve_slot_id');
            $table->string('reserve_request_meta')
                ->nullable()
                ->default('windos.s')
                ->after('payment_config_id');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['payment_config_id', 'reserve_request_meta']);
        });
    }
};
