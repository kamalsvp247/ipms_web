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
        Schema::create('pdf_edit_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('surname')->nullable();
            $table->string('given_name')->nullable();
            $table->string('passport_no')->nullable();
            $table->string('pdf_phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pdf_edit_profiles');
    }
};
