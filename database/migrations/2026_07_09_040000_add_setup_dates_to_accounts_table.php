<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Dhaka calendar date the bot last completed each setup step for. IVAC wipes the
            // appointment/PDFs/booking-config after every window, so setup must re-run daily —
            // the pdf_uploaded / booking_configured booleans alone would wrongly skip it the next
            // day. /api/config only reports a step as done when its stamp equals today's Dhaka date.
            $table->date('pdf_uploaded_date')->nullable()->after('pdf_uploaded');
            $table->date('booking_configured_date')->nullable()->after('booking_configured');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['pdf_uploaded_date', 'booking_configured_date']);
        });
    }
};
