<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The web file number IVAC prints under the photo barcode ("Application Id : BGDDVBA47626").
 *
 * Stored rather than parsed on read: the account list shows it per PDF, and decoding the Base64
 * content streams of every attachment on every page load is not something a list query can afford.
 * Backfill existing rows with `php artisan pdfs:application-ids`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_pdfs', function (Blueprint $table): void {
            $table->string('application_id', 32)->nullable()->after('name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('account_pdfs', function (Blueprint $table): void {
            $table->dropIndex(['application_id']);
            $table->dropColumn('application_id');
        });
    }
};
