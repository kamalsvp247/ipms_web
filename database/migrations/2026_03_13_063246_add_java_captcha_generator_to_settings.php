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
            $table->boolean('use_java_captcha_generator')->default(false)->after('captcha_page_url');
            $table->integer('captcha_generator_interval_ms')->default(5000)->after('use_java_captcha_generator');
            $table->integer('captcha_generator_max_tokens')->default(5)->after('captcha_generator_interval_ms');
            $table->string('captcha_bot_secret')->nullable()->after('captcha_generator_max_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'use_java_captcha_generator',
                'captcha_generator_interval_ms',
                'captcha_generator_max_tokens',
                'captcha_bot_secret',
            ]);
        });
    }
};
