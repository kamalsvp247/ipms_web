<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_sessions', function (Blueprint $table) {
            $table->string('reserved_visa_type', 64)->nullable()->after('last_file_overview');
            $table->unsignedInteger('reserved_applicants')->nullable()->after('reserved_visa_type');
        });
    }

    public function down(): void
    {
        Schema::table('account_sessions', function (Blueprint $table) {
            $table->dropColumn(['reserved_visa_type', 'reserved_applicants']);
        });
    }
};
