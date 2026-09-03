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
        Schema::table('ip_scan_sessions', function (Blueprint $table) {
            $table->string('region', 32)->nullable()->after('subnets');
            $table->enum('status', ['running', 'stopping', 'stopped', 'completed', 'paused'])
                ->default('running')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('ip_scan_sessions', function (Blueprint $table) {
            $table->dropColumn('region');
            $table->enum('status', ['running', 'stopping', 'stopped', 'completed'])
                ->default('running')
                ->change();
        });
    }
};
