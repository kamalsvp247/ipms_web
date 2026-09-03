<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_scan_sessions', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['running', 'stopping', 'stopped', 'completed'])->default('running');
            $table->json('subnets')->nullable();
            $table->unsignedInteger('total_candidates')->default(0);
            $table->unsignedInteger('probed_count')->default(0);
            $table->unsignedInteger('found_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_scan_sessions');
    }
};
