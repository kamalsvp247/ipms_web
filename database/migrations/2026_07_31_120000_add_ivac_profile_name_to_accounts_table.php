<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            // The IVAC account holder's registered name, as it stands after the bot corrects a
            // "Name mismatch" reported by the file-upload service. Kept so an operator can see what
            // the profile was changed to, and so the fix is attempted at most once per account.
            $table->string('ivac_profile_name')->nullable()->after('booking_city');
            $table->timestamp('profile_name_fixed_at')->nullable()->after('ivac_profile_name');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['ivac_profile_name', 'profile_name_fixed_at']);
        });
    }
};
