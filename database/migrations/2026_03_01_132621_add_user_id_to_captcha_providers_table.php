<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add nullable first so existing rows don't violate the constraint
        Schema::table('captcha_providers', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // Assign existing providers to the first super_admin user
        $superAdmin = User::where('role', 'super_admin')->first();
        if ($superAdmin) {
            DB::table('captcha_providers')->whereNull('user_id')->update(['user_id' => $superAdmin->id]);
        }

        // Make non-nullable now that all rows have a value
        Schema::table('captcha_providers', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('captcha_providers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
