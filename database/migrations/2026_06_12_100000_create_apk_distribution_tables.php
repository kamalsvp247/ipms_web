<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apk_app_infos', function (Blueprint $table) {
            $table->id();
            $table->string('app_title')->default('Duronto SMS Forwarder');
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->string('package_name')->nullable();
            $table->string('developer_name')->nullable();
            $table->string('developer_email')->nullable();
            $table->string('developer_website')->nullable();
            $table->string('logo_path')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('apk_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version_name');
            $table->unsignedInteger('version_code')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('checksum_sha256', 64);
            $table->text('changelog')->nullable();
            $table->string('min_android')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedBigInteger('download_count')->default(0);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('apk_screenshots', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->string('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apk_screenshots');
        Schema::dropIfExists('apk_releases');
        Schema::dropIfExists('apk_app_infos');
    }
};
