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
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_tick_shots')->default(10)->after('slot_tick_interval_ms');
            $table->unsignedInteger('payment_tick_interval_ms')->default(1000)->after('payment_tick_shots');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['payment_tick_shots', 'payment_tick_interval_ms']);
        });
    }
};
