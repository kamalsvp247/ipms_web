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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone');
            $table->text('password'); // Will be encrypted via model cast/mutator
            $table->boolean('proxy_enabled')->default(false);
            $table->integer('max_retries')->nullable();
            $table->integer('retry_delay_ms')->nullable();
            $table->integer('slot_retry_delay_ms')->nullable();
            $table->boolean('burst_enabled')->default(false);
            $table->integer('burst_proxy_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
