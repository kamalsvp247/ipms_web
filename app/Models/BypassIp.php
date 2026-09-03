<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BypassIp extends Model
{
    protected $fillable = ['label', 'ip', 'is_default', 'last_ping_ms', 'last_pinged_at', 'response_status', 'response_message', 'response_flag', 'response_time_ms'];

    protected function casts(): array
    {
        return [
            'last_pinged_at' => 'datetime',
            'last_ping_ms' => 'integer',
            'is_default' => 'boolean',
            'response_status' => 'integer',
            'response_flag' => 'boolean',
            'response_time_ms' => 'integer',
        ];
    }

    public static function getDefault(): ?self
    {
        return self::where('is_default', true)->first();
    }

    public function slots(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AgentSlot::class);
    }
}
