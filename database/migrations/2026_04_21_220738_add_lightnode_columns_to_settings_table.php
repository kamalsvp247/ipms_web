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
        Schema::table('settings', function (Blueprint $table) {
            $table->text('lightnode_api_token')->nullable()->after('captcha_daily_limit_per_account');
            $table->string('lightnode_region_code', 64)->nullable()->after('lightnode_api_token');
            $table->string('lightnode_zone_code', 64)->nullable()->after('lightnode_region_code');
            $table->string('lightnode_plan_code', 128)->nullable()->after('lightnode_zone_code');
            $table->string('lightnode_image_uuid', 128)->nullable()->after('lightnode_plan_code');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'lightnode_api_token',
                'lightnode_region_code',
                'lightnode_zone_code',
                'lightnode_plan_code',
                'lightnode_image_uuid',
            ]);
        });
    }
};
