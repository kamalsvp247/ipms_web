<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Forward proxy (host:port with Basic auth) the bot falls back to when sign-in hits
            // IVAC's edge/WAF 429 ("Too many request detected" — no server-stated cooldown,
            // meaning the origin IP itself is throttled, not the account). Delivered via
            // /api/config like reserve_slot_id/payment_config_id.
            $table->string('signin_429_proxy_url')
                ->nullable()
                ->default('http://customer-smensulaiman_0O1gd:OTUw=ks3N~8TUD@bd-pr.oxylabs.io:30000')
                ->after('reserve_request_meta');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('signin_429_proxy_url');
        });
    }
};
