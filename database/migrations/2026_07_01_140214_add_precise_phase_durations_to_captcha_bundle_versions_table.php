<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('captcha_bundle_versions', function (Blueprint $table) {
            // Precise phase durations captured in-memory (ms), independent of the
            // second-resolution timestamp columns. processing = download-complete ->
            // registration; reload = the sidecar re-evaluating the new bundle on activate.
            $table->unsignedInteger('processing_duration_ms')->nullable()->after('processing_completed_at');
            $table->unsignedInteger('reload_duration_ms')->nullable()->after('healthy_at');
        });
    }

    public function down(): void
    {
        Schema::table('captcha_bundle_versions', function (Blueprint $table) {
            $table->dropColumn(['processing_duration_ms', 'reload_duration_ms']);
        });
    }
};
