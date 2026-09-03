<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('captcha_algorithm_snapshots', function (Blueprint $table) {
            $table->boolean('login_magic_match')->default(false)->after('magic_numbers_match');
            $table->string('login_secret', 255)->nullable()->after('secret');
            $table->integer('login_skip')->nullable()->after('login_secret');
            $table->integer('login_enc_len')->nullable()->after('login_skip');
        });
    }

    public function down(): void
    {
        Schema::table('captcha_algorithm_snapshots', function (Blueprint $table) {
            $table->dropColumn(['login_magic_match', 'login_secret', 'login_skip', 'login_enc_len']);
        });
    }
};
