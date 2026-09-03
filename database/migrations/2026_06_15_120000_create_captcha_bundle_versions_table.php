<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captcha_bundle_versions', function (Blueprint $table) {
            $table->id();

            // Bundle identity / location
            $table->string('bundle_filename', 255)->nullable();
            $table->string('bundle_url', 512)->nullable();
            $table->string('bundle_hash', 64)->unique();

            // Full encrypt_meta document (activation source of truth)
            $table->text('meta_json')->nullable();

            // Denormalized per-type config for the list UI
            $table->integer('login_version')->nullable();
            $table->integer('login_skip')->nullable();
            $table->integer('login_enc_len')->nullable();
            $table->string('login_secret', 255)->nullable();
            $table->integer('reserve_version')->nullable();
            $table->integer('reserve_skip')->nullable();
            $table->integer('reserve_enc_len')->nullable();
            $table->string('reserve_secret', 255)->nullable();
            $table->string('charset', 128)->nullable();

            // Lifecycle
            $table->boolean('extraction_ok')->default(false);
            $table->boolean('is_active')->default(false)->index();
            $table->string('label', 255)->nullable();
            $table->timestamp('activated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captcha_bundle_versions');
    }
};
