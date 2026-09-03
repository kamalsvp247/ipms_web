<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the unused captcha_engine column. Encryption is sidecar-only (driven by
     * encrypt_meta.json); the engine selector was removed, so the setting no longer
     * affects anything.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('captcha_engine');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('captcha_engine', 16)->default('php')->after('captcha_daily_limit_per_account');
        });
    }
};
