<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every manager needs a unique code so an agent can self-register (Request Access) already
 * linked to the right manager. Backfills existing managers so none are left without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code')->nullable()->unique()->after('parent_id');
        });

        DB::table('users')->where('role', 'manager')->orderBy('id')->get(['id', 'name'])->each(function ($manager) {
            DB::table('users')->where('id', $manager->id)->update([
                'referral_code' => User::generateReferralCode($manager->name),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('referral_code');
        });
    }
};
