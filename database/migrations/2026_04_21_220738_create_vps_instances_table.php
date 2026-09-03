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
        Schema::create('vps_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_slot_id')->nullable()->constrained('agent_slots')->nullOnDelete();
            $table->string('provider', 32)->default('lightnode');
            $table->string('provider_instance_id', 128)->nullable();
            $table->string('region_code', 64)->nullable();
            $table->string('zone_code', 64)->nullable();
            $table->string('plan_code', 128)->nullable();
            $table->string('image_uuid', 128)->nullable();
            $table->string('instance_name', 100)->nullable();
            $table->string('public_ip', 45)->nullable();
            $table->text('root_password')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('status_message')->nullable();
            $table->timestamp('destroyed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vps_instances');
    }
};
