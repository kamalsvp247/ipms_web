<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_report_overrides', function (Blueprint $table) {
            $table->id();
            $table->date('report_date');
            $table->string('original_visa_type', 64);
            $table->string('visa_type', 64);
            $table->unsignedInteger('applicants');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['report_date', 'original_visa_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_overrides');
    }
};
