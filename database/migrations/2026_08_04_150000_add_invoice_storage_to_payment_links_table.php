<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache each paid reservation's IVAC invoice on disk.
 *
 * The invoice can only be fetched while the owning account still has an unexpired IVAC session
 * (899s from sign-in), so it has to be pulled shortly after the payment is confirmed rather than
 * on demand weeks later. These columns record where that copy landed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->string('invoice_path', 255)->nullable()->after('callback_completed_at');
            $table->timestamp('invoice_fetched_at')->nullable()->after('invoice_path');
        });
    }

    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropColumn(['invoice_path', 'invoice_fetched_at']);
        });
    }
};
