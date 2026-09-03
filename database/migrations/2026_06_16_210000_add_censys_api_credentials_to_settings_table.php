<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->string('censys_api_id')->nullable()->after('latest_jar_version');
            $table->string('censys_api_secret')->nullable()->after('censys_api_id');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn(['censys_api_id', 'censys_api_secret']);
        });
    }
};
