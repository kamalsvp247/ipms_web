<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpScanResult extends Model
{
    protected $fillable = [
        'scan_session_id',
        'ip',
        'label',
        'response_status',
        'response_message',
        'response_time_ms',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_time_ms' => 'integer',
        ];
    }

    public function session(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(IpScanSession::class, 'scan_session_id');
    }
}
