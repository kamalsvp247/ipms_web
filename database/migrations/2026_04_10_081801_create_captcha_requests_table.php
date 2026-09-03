<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captcha_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique()->index();
            $table->string('type');                                         // 'turnstile' | 'turnstile_encrypted'
            $table->string('status')->default('pending');                   // pending, processing, ready, failed, expired
            $table->text('token')->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('captcha_providers')->nullOnDelete();
            $table->unsignedBigInteger('agent_slot_id')->nullable();        // optional tracking, no FK constraint (open API)
            $table->string('vendor_task_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('solved_at')->nullable();                     // when provider returned the token
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captcha_requests');
    }
};
