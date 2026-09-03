<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Console output from the headless driver, kept per attempt so a failed auto payment can be
     * diagnosed from the portal instead of by tailing a worker journal.
     */
    public function up(): void
    {
        Schema::table('payment_automation_attempts', function (Blueprint $table) {
            $table->longText('driver_log')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('payment_automation_attempts', function (Blueprint $table) {
            $table->dropColumn('driver_log');
        });
    }
};
