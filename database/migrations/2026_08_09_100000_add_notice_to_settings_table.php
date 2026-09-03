<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single global notice broadcast to every signed-in user in the app header.
 *
 * It lives on the settings singleton rather than in its own table because
 * HandleInertiaRequests already loads Setting::instance() on every request, so the
 * banner reaches every page without a second query. The text is Bengali and is
 * rendered with the self-hosted SolaimanLipi face, hence a text column rather than
 * a short string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('notice_text')->nullable()->after('window_end_time');
            $table->boolean('notice_enabled')->default(false)->after('notice_text');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['notice_text', 'notice_enabled']);
        });
    }
};
