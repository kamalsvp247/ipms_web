<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('captcha_requests', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('agent_slot_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('captcha_requests', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
