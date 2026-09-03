<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Normalize existing rows: append ':00' if value is in HH:MM format
        DB::table('settings')->get()->each(function ($row) {
            $updates = [];

            if (preg_match('/^\d{2}:\d{2}$/', $row->window_start_time)) {
                $updates['window_start_time'] = $row->window_start_time.':00';
            }

            if (preg_match('/^\d{2}:\d{2}$/', $row->window_end_time)) {
                $updates['window_end_time'] = $row->window_end_time.':00';
            }

            if (! empty($updates)) {
                DB::table('settings')->where('id', $row->id)->update($updates);
            }
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->string('window_start_time')->default('00:00:00')->change();
            $table->string('window_end_time')->default('23:59:00')->change();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('window_start_time')->default('00:00')->change();
            $table->string('window_end_time')->default('23:59')->change();
        });
    }
};
