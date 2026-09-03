<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A completed payment blocks auto payment for the account, because IVAC keeps reissuing
     * checkout links and paying a second one charges the wallet twice for one booking. This
     * watermark is what turns that block into a per-booking-cycle rule rather than a permanent
     * one: only links newer than the re-arm count against the account.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->timestamp('auto_payment_rearmed_at')->nullable()->after('auto_payment_pin');
        });

        Schema::table('payment_links', function (Blueprint $table): void {
            // The pair every account-scoped lookup already filters on — the paid guard,
            // isSupersededForAccount() and the auto-payment sweep.
            $table->index(['account_phone', 'is_fake'], 'payment_links_account_phone_is_fake_index');
        });
    }

    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table): void {
            $table->dropIndex('payment_links_account_phone_is_fake_index');
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('auto_payment_rearmed_at');
        });
    }
};
