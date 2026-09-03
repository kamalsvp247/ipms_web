<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_scan_sessions', function (Blueprint $table) {
            $table->enum('status', ['running', 'pausing', 'stopping', 'stopped', 'completed', 'paused'])
                ->default('running')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('ip_scan_sessions', function (Blueprint $table) {
            $table->enum('status', ['running', 'stopping', 'stopped', 'completed', 'paused'])
                ->default('running')
                ->change();
        });
    }
};
