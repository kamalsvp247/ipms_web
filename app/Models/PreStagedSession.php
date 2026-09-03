<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreStagedSession extends Model
{
    protected $fillable = [
        'phone',
        'request_id',
        'otp_code',
        'otp_captured_at',
    ];

    protected function casts(): array
    {
        return [
            'otp_captured_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'phone', 'phone');
    }
}
