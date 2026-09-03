<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('captcha_requests', function (Blueprint $table) {
            // Links one racing solve attempt to the on-demand row the bot is polling.
            //
            // Deliberately not a foreign key: the parent row is deleted the instant the bot
            // consumes its token, and a cascade would take the still-running attempts with
            // it before they can bank their own tokens into the pool. A dangling parent id
            // is the normal end state and means exactly "nobody is waiting for this any more".
            $table->unsignedBigInteger('race_parent_id')->nullable()->after('source');

            // Every settle asks "is any sibling still racing?", which is this pair.
            $table->index(['race_parent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('captcha_requests', function (Blueprint $table) {
            $table->dropIndex(['race_parent_id', 'status']);
            $table->dropColumn('race_parent_id');
        });
    }
};
