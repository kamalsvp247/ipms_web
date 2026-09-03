<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-account auto-payment credentials. When `auto_payment` is on and a dg-epay payment link
     * lands for the account, the portal drives the checkout headlessly instead of waiting for a
     * human. The PIN is encrypted by the model accessor, never stored in clear.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('auto_payment')->default(false)->after('booking_city');
            $table->string('auto_payment_method', 16)->nullable()->after('auto_payment');
            $table->string('auto_payment_wallet', 20)->nullable()->after('auto_payment_method');
            $table->text('auto_payment_pin')->nullable()->after('auto_payment_wallet');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'auto_payment',
                'auto_payment_method',
                'auto_payment_wallet',
                'auto_payment_pin',
            ]);
        });
    }
};
