<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds the deployment-scoped parts of Cloudflare's Turnstile challenge flow.
 *
 * Cloudflare rotates the challenge branch letter, the api.js asset hash and the flow's
 * deploy triple on its own schedule. Keeping them here rather than in the emulator means a
 * rotation is a config change, exactly as settings.ivac_endpoints already does for IVAC.
 * Per-session values (cf-ray, the challenge tokens, timestamps) are deliberately NOT stored
 * — they are read from the live bootstrap on every solve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->json('turnstile_endpoints')->nullable()->after('ivac_endpoints');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('turnstile_endpoints');
        });
    }
};
