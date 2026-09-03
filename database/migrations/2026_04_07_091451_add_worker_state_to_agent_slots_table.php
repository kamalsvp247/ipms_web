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
            $table->enum('worker_state', ['idle', 'running', 'stopping'])->default('idle')->after('status');
            $table->string('pending_command')->nullable()->after('worker_state');
        });
    }

    public function down(): void
    {
        Schema::table('agent_slots', function (Blueprint $table) {
            $table->dropColumn(['worker_state', 'pending_command']);
        });
    }
};
