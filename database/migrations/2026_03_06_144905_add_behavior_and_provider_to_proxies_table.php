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
        Schema::table('proxies', function (Blueprint $table) {
            if (! Schema::hasColumn('proxies', 'behavior')) {
                $table->string('behavior')->default('sticky')->after('type');
            }
            if (! Schema::hasColumn('proxies', 'provider')) {
                $table->string('provider')->nullable()->after('behavior');
            }
        });
    }

    public function down(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            if (Schema::hasColumn('proxies', 'provider')) {
                $table->dropColumn('provider');
            }
            if (Schema::hasColumn('proxies', 'behavior')) {
                $table->dropColumn('behavior');
            }
        });
    }
};
