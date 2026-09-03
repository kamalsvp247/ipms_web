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
        Schema::table('account_sessions', function (Blueprint $table) {
            $table->timestamp('jwt_generated_at')->nullable()->after('jwt_token');
            $table->timestamp('jwt_expires_at')->nullable()->after('jwt_generated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_sessions', function (Blueprint $table) {
            $table->dropColumn(['jwt_generated_at', 'jwt_expires_at']);
        });
    }
};
