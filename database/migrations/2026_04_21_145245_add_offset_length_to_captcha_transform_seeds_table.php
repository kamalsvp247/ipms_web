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
        Schema::table('captcha_transform_seeds', function (Blueprint $table) {
            $table->unsignedSmallInteger('offset')->default(9)->after('seed');
            $table->unsignedSmallInteger('length')->default(19)->after('offset');
        });
    }

    public function down(): void
    {
        Schema::table('captcha_transform_seeds', function (Blueprint $table) {
            $table->dropColumn(['offset', 'length']);
        });
    }
};
