<?php

use Illuminate\Database\Migrations\Migration;

/**
 * This migration is superseded by the columnar settings table migration.
 * The skip_get_booking_config setting has been removed entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op: settings table has been restructured to columnar format.
    }

    public function down(): void
    {
        // No-op.
    }
};
