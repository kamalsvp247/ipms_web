<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class VpsInstance extends Model
{
    protected $fillable = [
        'agent_slot_id',
        'captcha_node_id',
        'captcha_status',
        'captcha_message',
        'role',
        'provider',
        'provider_instance_id',
        'region_code',
        'zone_code',
        'plan_code',
        'image_uuid',
        'instance_name',
        'public_ip',
        'root_password',
        'status',
        'status_message',
        'ssh_username',
        'destroyed_at',
        'update_status',
    ];

    protected function casts(): array
    {
        return [
            'destroyed_at' => 'datetime',
        ];
    }

    protected function rootPassword(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null) {
                    return null;
                }
                try {
                    return Crypt::decrypt($value);
                } catch (\Exception $e) {
                    return null;
                }
            },
            set: fn ($value) => $value !== null ? Crypt::encrypt($value) : null,
        );
    }

    public function agentSlot(): BelongsTo
    {
        return $this->belongsTo(AgentSlot::class);
    }

    public function captchaNode(): BelongsTo
    {
        return $this->belongsTo(CaptchaNode::class);
    }

    /**
     * Provisioned specifically as a solver box, as opposed to a bot worker that also
     * happens to run the solver alongside ipms-bot.
     */
    public function isCaptchaNode(): bool
    {
        return $this->role === 'captcha';
    }

    /**
     * The solver is installed here, whatever this box was originally provisioned for.
     */
    public function hasCaptchaSolver(): bool
    {
        return $this->captcha_node_id !== null;
    }

    /**
     * A bot worker running the solver alongside ipms-bot has to yield CPU to it; a
     * dedicated solver box does not.
     */
    public function captchaProfile(): string
    {
        return $this->role === 'captcha' ? 'dedicated' : 'shared';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['online', 'failed', 'destroyed']);
    }
}
