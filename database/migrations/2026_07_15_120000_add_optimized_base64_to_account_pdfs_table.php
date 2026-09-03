<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Holds the Ghostscript-optimized copy of each applicant PDF alongside the pristine original.
     * The Java bot uploads optimized_base64 when present (far fewer bytes = faster POST /file/upload-file
     * to IVAC). The original base64 column is never mutated, so the optimized copy can always be
     * re-derived at a different quality and the row stays revertible.
     */
    public function up(): void
    {
        Schema::table('account_pdfs', function (Blueprint $table): void {
            $table->longText('optimized_base64')->nullable()->after('base64');
            $table->unsignedInteger('original_size')->nullable()->after('optimized_base64');
            $table->unsignedInteger('optimized_size')->nullable()->after('original_size');
        });
    }

    public function down(): void
    {
        Schema::table('account_pdfs', function (Blueprint $table): void {
            $table->dropColumn(['optimized_base64', 'original_size', 'optimized_size']);
        });
    }
};
