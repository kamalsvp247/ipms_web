<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // 0 = unlimited; any positive integer = max captcha requests per account per day
            $table->unsignedInteger('captcha_daily_limit_per_account')->default(0)->after('captcha_bot_secret');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('captcha_daily_limit_per_account');
        });
    }
};
