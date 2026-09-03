<?php

namespace App\Models;

use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaptchaRequest extends Model
{
    protected $fillable = [
        'request_id',
        'type',
        'status',
        'source',
        'race_parent_id',
        'token',
        'provider_id',
        'node_id',
        'leased_at',
        'lease_expires_at',
        'lease_attempts',
        'agent_slot_id',
        'phone',
        'vendor_task_id',
        'error_message',
        'solved_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => CaptchaTokenType::class,
            'status' => CaptchaRequestStatus::class,
            'solved_at' => 'datetime',
            'leased_at' => 'datetime',
            'lease_expires_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CaptchaProvider::class, 'provider_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(CaptchaNode::class, 'node_id');
    }

    /**
     * The on-demand row this solve attempt is racing to fill. Null for anything that is
     * not a racing attempt, and dangling once the bot has consumed its token.
     */
    public function raceParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'race_parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function raceAttempts(): HasMany
    {
        return $this->hasMany(self::class, 'race_parent_id');
    }
}
