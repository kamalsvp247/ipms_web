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
            $table->string('recaptcha_site_key')->nullable()->default('6LdyiGMsAAAAAJefesdWMjxy8pu3A3DmbeJkkdUl')->after('captcha_page_url');
            $table->string('recaptcha_page_url')->nullable()->default('https://appointment.ivacbd.com/')->after('recaptcha_site_key');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['recaptcha_site_key', 'recaptcha_page_url']);
        });
    }
};
