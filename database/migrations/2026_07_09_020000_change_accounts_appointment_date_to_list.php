<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('appointment_date');
        });

        Schema::table('accounts', function (Blueprint $table) {
            // One or more appointment dates. The bot rotates through them, sending one
            // per reserve attempt.
            $table->json('appointment_dates')->nullable()->after('appointment_id_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('appointment_dates');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('appointment_date')->nullable()->after('appointment_id_updated_at');
        });
    }
};
