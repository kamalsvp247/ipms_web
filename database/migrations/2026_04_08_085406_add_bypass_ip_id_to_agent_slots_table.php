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
        Schema::table('agent_slots', function (Blueprint $table) {
            $table->foreignId('bypass_ip_id')->nullable()->after('ip_address')
                ->constrained('bypass_ips')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_slots', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\BypassIp::class);
            $table->dropColumn('bypass_ip_id');
        });
    }
};
