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
        Schema::table('bot_logs', function (Blueprint $table) {
            $table->string('remote_ip', 45)->nullable()->after('protocol_version');
        });
    }

    public function down(): void
    {
        Schema::table('bot_logs', function (Blueprint $table) {
            $table->dropColumn('remote_ip');
        });
    }
};
