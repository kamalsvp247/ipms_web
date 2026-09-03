<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable per-day tally of in-house solves.
 *
 * captcha_requests is purged minutes after a token is issued, and a node's own solved
 * counter resets whenever its process restarts, so neither can answer "how many did we
 * generate this week". This table is the only lasting record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captcha_node_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('captcha_node_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('solved')->default(0);
            $table->unsignedBigInteger('failed')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);
            $table->timestamps();

            // One row per node per day — the upsert in CaptchaNodeDailyStat::record()
            // relies on this to stay atomic under concurrent result POSTs.
            $table->unique(['date', 'captcha_node_id']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captcha_node_daily_stats');
    }
};
