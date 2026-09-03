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
        Schema::table('bot_logs', function (Blueprint $table) {
            $table->timestamp('logged_at', 3)->useCurrent()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_logs', function (Blueprint $table) {
            $table->timestamp('logged_at')->useCurrent()->change();
        });
    }
};
