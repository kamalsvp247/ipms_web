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
            $table->string('token_type')->nullable();
            $table->integer('expires_at')->nullable();
            $table->string('user_id')->nullable();
            $table->string('request_id')->nullable();
            $table->json('roles')->nullable();
            $table->integer('status_code')->nullable();
            $table->text('message')->nullable();
            $table->boolean('success_flag')->nullable();
            $table->string('server_time')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'token_type',
                'expires_at',
                'user_id',
                'request_id',
                'roles',
                'status_code',
                'message',
                'success_flag',
                'server_time',
            ]);
        });
    }
};
