<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('captcha_bundle_versions', function (Blueprint $table) {
            // When the bundle download started (from the Python analyzer).
            $table->timestamp('download_started_at')->nullable()->after('bundle_hash');
            // How long the download itself took.
            $table->unsignedInteger('download_duration_ms')->nullable()->after('download_started_at');
            // When extraction/attribution/registration finished (download complete -> this point).
            $table->timestamp('processing_completed_at')->nullable()->after('extraction_ok');
            // When the sidecar last confirmed it reloaded this bundle successfully.
            $table->timestamp('healthy_at')->nullable()->after('activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('captcha_bundle_versions', function (Blueprint $table) {
            $table->dropColumn(['download_started_at', 'download_duration_ms', 'processing_completed_at', 'healthy_at']);
        });
    }
};
