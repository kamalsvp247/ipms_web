<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('captcha_transform_seeds', function (Blueprint $table) {
            $table->enum('token_type', ['login', 'reserve'])->after('id')->default('reserve');
        });

        // Swap unique constraint: seed alone → (token_type, seed)
        Schema::table('captcha_transform_seeds', function (Blueprint $table) {
            $table->dropUnique('captcha_transform_seeds_seed_unique');
            $table->unique(['token_type', 'seed'], 'captcha_transform_seeds_type_seed_unique');
        });
    }

    public function down(): void
    {
        Schema::table('captcha_transform_seeds', function (Blueprint $table) {
            $table->dropUnique('captcha_transform_seeds_type_seed_unique');
            $table->unique('seed', 'captcha_transform_seeds_seed_unique');
            $table->dropColumn('token_type');
        });
    }
};
