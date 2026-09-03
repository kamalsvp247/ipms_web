<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class AgentSlot extends Model
{
    protected $fillable = [
        'name',
        'api_key',
        'status',
        'worker_state',
        'pending_command',
        'last_heartbeat_at',
        'ip_address',
        'bypass_ip_id',
        'bot_version',
    ];

    protected $casts = [
        'last_heartbeat_at' => 'datetime',
    ];

    public static function generateApiKey(): string
    {
        return Str::random(64);
    }

    public function bypassIp(): BelongsTo
    {
        return $this->belongsTo(BypassIp::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function botLogs(): HasMany
    {
        return $this->hasMany(BotLog::class);
    }

    public function publishCommand(string $command): void
    {
        $this->update(['pending_command' => $command]);

        Redis::publish(
            "slot:{$this->id}:commands",
            json_encode(['command' => $command, 'issued_at' => now()->toIso8601String()])
        );
    }

    public function clearPendingCommand(): void
    {
        $this->update(['pending_command' => null]);
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    public function markOnline(?string $ipAddress = null): void
    {
        $data = [
            'status' => 'online',
            'last_heartbeat_at' => now(),
        ];

        if ($ipAddress !== null) {
            $data['ip_address'] = $ipAddress;
        }

        $this->update($data);
    }

    public function markOfflineIfStale(): void
    {
        if ($this->last_heartbeat_at && $this->last_heartbeat_at->diffInSeconds(now()) > 90) {
            $this->update(['status' => 'offline']);
        }
    }
}
