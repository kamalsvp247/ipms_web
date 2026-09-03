<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Applicant PDFs stored as Base64. Array of {name, base64, is_primary}.
            // Exactly one entry is marked primary. Delivered to the bot via a dedicated
            // endpoint (GET /api/accounts/{id}/pdfs), never inside /api/config.
            $table->json('pdfs')->nullable()->after('appointment_dates');
            // One-time setup flags. The bot uploads PDFs and posts the booking config
            // once per account; both persist so a bot restart does not repeat them.
            $table->boolean('pdf_uploaded')->default(false)->after('pdfs');
            $table->boolean('booking_configured')->default(false)->after('pdf_uploaded');
            // Selected IVAC centre city (Dhaka/Khulna/Chittagong/Rajshahi/Sylhet).
            $table->string('booking_city')->nullable()->after('booking_configured');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['pdfs', 'pdf_uploaded', 'booking_configured', 'booking_city']);
        });
    }
};
