<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_scan_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_session_id')->constrained('ip_scan_sessions')->cascadeOnDelete();
            $table->string('ip', 45);
            $table->string('label', 100);
            $table->unsignedInteger('response_status')->nullable();
            $table->string('response_message', 512)->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamps();

            $table->unique(['scan_session_id', 'ip']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_scan_results');
    }
};
