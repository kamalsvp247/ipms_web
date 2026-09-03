<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A daily time range during which agents may not edit or delete their own accounts.
 *
 * The window is a wall-clock time of day in Dhaka time, repeating every day, so only the
 * time-of-day parts are stored. Managers and super admins are never subject to it. Start
 * later than end means the window wraps past midnight, which is the common case since the
 * booking window itself sits around midnight.
 *
 * It lives on the settings singleton so HandleInertiaRequests can share it with the front
 * end on every request without a second query. The times default to the 09:00-21:00 working
 * day and the lock is on out of the box; account_lock_enabled switches it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('account_lock_enabled')->default(true)->after('window_end_time');
            $table->time('account_lock_start_time')->nullable()->default('09:00:00')->after('account_lock_enabled');
            $table->time('account_lock_end_time')->nullable()->default('21:00:00')->after('account_lock_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['account_lock_enabled', 'account_lock_start_time', 'account_lock_end_time']);
        });
    }
};
