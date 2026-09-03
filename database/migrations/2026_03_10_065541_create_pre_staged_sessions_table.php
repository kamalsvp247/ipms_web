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
        Schema::create('pre_staged_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 32)->unique();
            $table->string('request_id', 64);
            $table->string('otp_code', 16)->nullable();
            $table->timestamp('otp_captured_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pre_staged_sessions');
    }
};
