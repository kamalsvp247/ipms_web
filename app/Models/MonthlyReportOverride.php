<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyReportOverride extends Model
{
    protected $fillable = [
        'report_date',
        'original_visa_type',
        'visa_type',
        'applicants',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'applicants' => 'integer',
        ];
    }

    public function updatedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
