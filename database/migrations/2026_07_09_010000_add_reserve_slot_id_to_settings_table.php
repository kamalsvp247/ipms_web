<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // IVAC embeds a fixed, deployment-scoped slot ID in the reserve URL
            // (POST /slots/{id}/reserve-slot). It rotates when IVAC redeploys the
            // frontend bundle, so it lives here as an editable setting rather than
            // a hardcoded constant in the bot.
            $table->string('reserve_slot_id')->nullable()->default('ccd3dd63-e781-48ba-a48d-c65eaa4fc663')->after('window_end_time');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('reserve_slot_id');
        });
    }
};
