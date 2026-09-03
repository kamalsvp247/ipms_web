<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vps_instances', function (Blueprint $table) {
            // Tracks an in-flight solver install/uninstall on THIS box, kept apart from
            // update_status (which belongs to the bot) so a captcha install and a bot
            // update can be in flight at once without overwriting each other's state.
            $table->string('captcha_status')->nullable()->after('captcha_node_id');
            $table->string('captcha_message')->nullable()->after('captcha_status');
        });
    }

    public function down(): void
    {
        Schema::table('vps_instances', function (Blueprint $table) {
            $table->dropColumn(['captcha_status', 'captcha_message']);
        });
    }
};
