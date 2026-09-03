<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'slot_retry_delay_ms',
                'slot_probe_delay_ms',
                'bypass_slot_parallel_shots',
                'otp_verify_burst_delay_ms',
            ]);

            $table->unsignedSmallInteger('otp_tick_shots')->default(10)->after('signin_parallel_clients');
            $table->unsignedInteger('otp_tick_interval_ms')->default(1000)->after('otp_tick_shots');
            $table->unsignedSmallInteger('slot_tick_shots')->default(10)->after('otp_tick_interval_ms');
            $table->unsignedInteger('slot_tick_interval_ms')->default(1000)->after('slot_tick_shots');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'otp_tick_shots',
                'otp_tick_interval_ms',
                'slot_tick_shots',
                'slot_tick_interval_ms',
            ]);

            $table->integer('slot_retry_delay_ms')->nullable();
            $table->integer('slot_probe_delay_ms')->nullable();
            $table->unsignedSmallInteger('bypass_slot_parallel_shots')->nullable()->default(4);
            $table->unsignedInteger('otp_verify_burst_delay_ms')->nullable()->default(1000);
        });
    }
};
