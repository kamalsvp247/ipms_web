<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('captcha_requests', function (Blueprint $table) {
            $table->foreignId('node_id')->nullable()->after('provider_id')
                ->constrained('captcha_nodes')->nullOnDelete();

            // Set when a node claims the request. lease_expires_at is what the reaper
            // compares against, so it is authoritative and portal-side — a node never
            // asserts its own clock.
            $table->timestamp('leased_at')->nullable()->after('node_id');
            $table->timestamp('lease_expires_at')->nullable()->after('leased_at');

            // An expired lease is requeued once; the second expiry fails the request
            // rather than cycling a poisoned row through the fleet forever.
            $table->unsignedTinyInteger('lease_attempts')->default(0)->after('lease_expires_at');

            $table->index(['status', 'lease_expires_at'], 'captcha_requests_lease_index');
        });
    }

    public function down(): void
    {
        Schema::table('captcha_requests', function (Blueprint $table) {
            $table->dropIndex('captcha_requests_lease_index');
            $table->dropForeign(['node_id']);
            $table->dropColumn(['node_id', 'leased_at', 'lease_expires_at', 'lease_attempts']);
        });
    }
};
