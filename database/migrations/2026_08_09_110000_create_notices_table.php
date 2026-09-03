<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notices move off the settings singleton into their own table so several can run
 * at once, each switched on or off independently. The header marquee shows every
 * enabled row in sort order, so the two settings columns are dropped here after the
 * existing single notice is carried over as the first row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->text('text');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_enabled', 'sort_order']);
        });

        if (Schema::hasColumn('settings', 'notice_text')) {
            $existing = DB::table('settings')->select('notice_text', 'notice_enabled')->first();

            if ($existing && trim((string) $existing->notice_text) !== '') {
                DB::table('notices')->insert([
                    'text' => trim((string) $existing->notice_text),
                    'is_enabled' => (bool) $existing->notice_enabled,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn(['notice_text', 'notice_enabled']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('notice_text')->nullable()->after('window_end_time');
            $table->boolean('notice_enabled')->default(false)->after('notice_text');
        });

        Schema::dropIfExists('notices');
    }
};
