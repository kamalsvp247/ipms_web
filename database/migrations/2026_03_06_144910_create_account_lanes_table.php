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
        Schema::create('account_lanes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position'); // 0 = Primary, 1 = Lane 2, 2 = Lane 3
            $table->string('lane_type');             // 'proxy' | 'local_ip'
            $table->foreignId('proxy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('local_ip_id')->nullable()->constrained('local_ips')->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_lanes');
    }
};
