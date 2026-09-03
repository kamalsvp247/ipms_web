<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table): void {
            $table->dropColumn(['publish_status', 'publish_status_code', 'publish_response']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table): void {
            $table->string('publish_status')->nullable();
            $table->integer('publish_status_code')->nullable();
            $table->text('publish_response')->nullable();
        });
    }
};
