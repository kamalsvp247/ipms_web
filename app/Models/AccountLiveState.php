<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountLiveState extends Model
{
    protected $table = 'account_live_states';

    protected $fillable = [
        'phone',
        'agent_slot_id',
        'phase',
        'method',
        'url',
        'status_code',
        'duration_ms',
        'message',
        'error_type',
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'phone', 'phone');
    }
}
