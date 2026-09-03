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
        Schema::table('payment_links', function (Blueprint $table) {
            $table->string('publish_status', 16)->nullable()->after('is_clicked');
            $table->unsignedSmallInteger('publish_status_code')->nullable()->after('publish_status');
            $table->text('publish_response')->nullable()->after('publish_status_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropColumn(['publish_status', 'publish_status_code', 'publish_response']);
        });
    }
};
