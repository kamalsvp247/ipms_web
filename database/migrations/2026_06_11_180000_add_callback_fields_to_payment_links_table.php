<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table): void {
            $table->string('reservation_id')->nullable()->index()->after('account_phone');
            $table->text('callback_url')->nullable()->after('redirect_gateway_url');
            $table->string('callback_status', 16)->nullable()->after('callback_url');
            $table->integer('callback_status_code')->nullable()->after('callback_status');
            $table->longText('callback_response')->nullable()->after('callback_status_code');
            $table->timestamp('callback_submitted_at')->nullable()->after('callback_response');
            $table->timestamp('callback_completed_at')->nullable()->after('callback_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table): void {
            $table->dropColumn([
                'reservation_id',
                'callback_url',
                'callback_status',
                'callback_status_code',
                'callback_response',
                'callback_submitted_at',
                'callback_completed_at',
            ]);
        });
    }
};
