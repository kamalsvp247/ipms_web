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
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->string('gateway_page_url', 2048)->nullable();
            $table->string('redirect_gateway_url', 2048)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('message')->nullable();
            $table->boolean('success_flag')->nullable();
            $table->string('server_time')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
