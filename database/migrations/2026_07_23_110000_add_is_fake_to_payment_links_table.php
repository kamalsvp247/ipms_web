<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flags synthetic payment-link rows so they can be filtered/purged later. Real bot-produced links
     * always keep this false; the payment-links:seed-fakes command sets it true on the rows it creates.
     */
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->boolean('is_fake')->default(false)->index()->after('is_clicked');
        });
    }

    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropColumn('is_fake');
        });
    }
};
