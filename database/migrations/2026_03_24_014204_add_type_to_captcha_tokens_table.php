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
        Schema::table('captcha_tokens', function (Blueprint $table) {
            $table->enum('type', ['turnstile', 'turnstile_encrypted', 'recaptcha'])
                ->default('turnstile')
                ->after('provider_id');
        });
    }

    public function down(): void
    {
        Schema::table('captcha_tokens', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
