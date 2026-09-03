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
            if (! Schema::hasColumn('accounts', 'agent_slot_id')) {
                $table->foreignId('agent_slot_id')->nullable()->after('user_id')->constrained('agent_slots')->nullOnDelete();
            } else {
                // Column was added in a prior partial run; just add the FK constraint
                $table->foreign('agent_slot_id')->references('id')->on('agent_slots')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\AgentSlot::class);
            $table->dropColumn('agent_slot_id');
        });
    }
};
