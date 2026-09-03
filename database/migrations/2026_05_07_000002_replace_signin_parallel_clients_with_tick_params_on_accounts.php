<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('signin_parallel_clients');

            $table->unsignedSmallInteger('signin_tick_shots')->default(10)->after('retry_delay_ms');
            $table->unsignedInteger('signin_tick_interval_ms')->default(1000)->after('signin_tick_shots');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['signin_tick_shots', 'signin_tick_interval_ms']);
            $table->unsignedTinyInteger('signin_parallel_clients')->default(1);
        });
    }
};
