<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('account_lanes');
        Schema::dropIfExists('local_ips');
        Schema::dropIfExists('proxies');
    }

    public function down(): void
    {
        // Tables were dropped; restore manually if needed
    }
};
