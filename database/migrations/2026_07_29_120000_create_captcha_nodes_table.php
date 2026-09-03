<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captcha_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('api_key', 64)->unique();
            $table->boolean('enabled')->default(true);

            // 'shared' is a box that also runs ipms-bot: it installs with a lower CPUQuota
            // and CPUWeight so the bot always wins contention during the booking window.
            $table->enum('profile', ['dedicated', 'shared'])->default('dedicated');

            $table->enum('status', ['online', 'offline'])->default('offline');
            $table->enum('worker_state', ['idle', 'solving', 'paused'])->default('idle');
            $table->string('pending_command')->nullable();

            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('hostname')->nullable();
            $table->string('script_version', 20)->nullable();

            $table->unsignedSmallInteger('cpu_cores')->nullable();
            // Portal-set override pushed back on every heartbeat so it survives a node
            // restart. Null means the node keeps whatever its systemd unit sized it to.
            $table->unsignedSmallInteger('concurrency')->nullable();
            $table->unsignedSmallInteger('reported_concurrency')->nullable();

            $table->unsignedSmallInteger('active')->default(0);
            $table->unsignedSmallInteger('queued')->default(0);
            $table->unsignedInteger('solved')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('avg_ms')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamps();

            // capacity() sums reported_concurrency over exactly this predicate.
            $table->index(['enabled', 'status', 'worker_state'], 'captcha_nodes_available_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captcha_nodes');
    }
};
