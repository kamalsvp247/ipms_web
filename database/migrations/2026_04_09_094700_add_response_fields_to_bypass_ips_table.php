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
        Schema::table('bypass_ips', function (Blueprint $table) {
            $table->integer('response_status')->nullable();
            $table->text('response_message')->nullable();
            $table->boolean('response_flag')->nullable();
            $table->integer('response_time_ms')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bypass_ips', function (Blueprint $table) {
            $table->dropColumn(['response_status', 'response_message', 'response_flag', 'response_time_ms']);
        });
    }
};
