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
        Schema::create('bypass_ips', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('ip', 45); // IPv4 or IPv6
            $table->integer('last_ping_ms')->nullable();
            $table->timestamp('last_pinged_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bypass_ips');
    }
};
