<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per account phone holding only the most recent IVAC API call the bot made.
     *
     * This deliberately does not read from `bot_logs`: the every-minute scheduled purge in
     * routes/console.php deletes all 4xx except 429 plus every `error_type` row, so the latest
     * row there stops being the latest call within a minute and the interesting failures
     * (400 captcha, 401 pre-OTP) disappear entirely. Keeping a separate upserted row makes the
     * live state survive the purge and turns the read into one indexed row per account instead
     * of a GROUP BY MAX(id) over a table that grows fast during window open.
     */
    public function up(): void
    {
        Schema::create('account_live_states', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 30)->unique();
            $table->unsignedBigInteger('agent_slot_id')->nullable();
            $table->string('phase', 40)->nullable();
            $table->string('method', 10);
            $table->text('url');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('message', 255)->nullable();
            $table->string('error_type', 100)->nullable();
            // Not nullable: the conditional upsert compares against this to reject a late batch,
            // and a NULL comparison would silently take the "keep old row" branch forever.
            $table->timestamp('logged_at', 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_live_states');
    }
};
