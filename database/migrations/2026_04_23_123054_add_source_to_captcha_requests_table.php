<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('captcha_requests', function (Blueprint $table) {
            $table->string('source')->default('on_demand')->after('status'); // pool | on_demand
            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('captcha_requests', function (Blueprint $table) {
            $table->dropIndex(['source', 'status']);
            $table->dropColumn('source');
        });
    }
};
