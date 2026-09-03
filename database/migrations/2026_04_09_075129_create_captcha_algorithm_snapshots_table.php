<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('captcha_algorithm_snapshots', function (Blueprint $table) {
            $table->id();

            // Bundle location
            $table->string('bundle_url', 512)->nullable();
            $table->string('bundle_filename', 255)->nullable(); // Just the filename part for display

            // Core algorithm constants
            $table->integer('offset')->nullable();
            $table->integer('length')->nullable();
            $table->boolean('magic_numbers_match')->default(false);

            // Captcha encryption constants
            $table->string('charset', 128)->nullable();
            $table->string('secret', 255)->nullable();
            $table->integer('skip')->nullable();
            $table->integer('encrypt_len')->nullable();

            // Extracted code
            $table->text('js_function')->nullable();
            $table->text('captcha_encryption')->nullable();

            // Fingerprint: hash of key fields to detect duplicate vs changed
            $table->string('fingerprint', 64)->nullable()->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('captcha_algorithm_snapshots');
    }
};
