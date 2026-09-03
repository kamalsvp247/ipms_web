<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpScanSession extends Model
{
    protected $fillable = [
        'type',
        'status',
        'subnets',
        'region',
        'meta',
        'total_candidates',
        'probed_count',
        'found_count',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'subnets' => 'array',
            'meta' => 'array',
            'total_candidates' => 'integer',
            'probed_count' => 'integer',
            'found_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function results(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IpScanResult::class, 'scan_session_id');
    }

    public function pendingResults(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IpScanResult::class, 'scan_session_id')->where('status', 'pending');
    }

    public static function latestActive(): ?self
    {
        return self::latest()->first();
    }

    public function isRunning(): bool
    {
        return $this->status === 'running' || $this->status === 'stopping';
    }
}
