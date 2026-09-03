<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->text('message')->nullable()->after('otp_code');
            $table->boolean('is_ivacbd')->default(false)->after('message');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE otp_codes MODIFY otp_code VARCHAR(10) NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE otp_codes MODIFY otp_code VARCHAR(10) NOT NULL');
        }

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn(['message', 'is_ivacbd']);
        });
    }
};
