<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user mobile wallet accounts (Rocket/Nagad numbers + PIN). Personal to the owning user —
     * nobody else, including managers/admins, reads or writes another user's rows. The PIN is
     * encrypted by the model accessor, never stored in clear.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 16);
            $table->string('wallet_number', 20);
            $table->text('pin');
            $table->string('label', 64)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
