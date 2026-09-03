<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vps_instances', function (Blueprint $table) {
            // Every existing row is a bot worker, so the default keeps them correct.
            $table->enum('role', ['bot', 'captcha'])->default('bot')->after('provider');

            // Kept alongside agent_slot_id rather than replacing it: a box can in principle
            // be both, and the two lifecycles are independent.
            $table->foreignId('captcha_node_id')->nullable()->after('agent_slot_id')
                ->constrained('captcha_nodes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vps_instances', function (Blueprint $table) {
            $table->dropForeign(['captcha_node_id']);
            $table->dropColumn(['role', 'captcha_node_id']);
        });
    }
};
