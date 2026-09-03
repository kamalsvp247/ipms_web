<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotLog extends Model
{
    protected $table = 'bot_logs';
    public $timestamps = false;

    protected $fillable = [
        'agent_slot_id',
        'account_phone',
        'label',
        'method',
        'url',
        'status_code',
        'duration_ms',
        'protocol_version',
        'remote_ip',
        'request_body',
        'response_body',
        'error_type',
        'error_message',
        'logged_at',
    ];

    protected $casts = [
        'logged_at' => 'datetime',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function agentSlot(): BelongsTo
    {
        return $this->belongsTo(AgentSlot::class);
    }
}
