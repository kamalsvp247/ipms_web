<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A machine that solves Turnstile captchas for the portal.
 *
 * Deliberately shaped like AgentSlot: the node pulls work over the heartbeat rather than
 * exposing a port, so a solver VPS needs nothing inbound and the sidecar's unauthenticated
 * :8788 stays bound to loopback. The portal host registers as a node too.
 */
class CaptchaNode extends Model
{
    /** @use HasFactory<\Database\Factories\CaptchaNodeFactory> */
    use HasFactory;

    /** A heartbeat older than this marks the node offline, matching AgentSlot. */
    private const STALE_AFTER_SECONDS = 90;

    protected $fillable = [
        'name',
        'api_key',
        'enabled',
        'profile',
        'status',
        'worker_state',
        'pending_command',
        'last_heartbeat_at',
        'ip_address',
        'hostname',
        'script_version',
        'cpu_cores',
        'concurrency',
        'reported_concurrency',
        'active',
        'queued',
        'solved',
        'failed',
        'avg_ms',
        'last_error',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_heartbeat_at' => 'datetime',
    ];

    public static function generateApiKey(): string
    {
        return Str::random(64);
    }

    public function captchaRequests(): HasMany
    {
        return $this->hasMany(CaptchaRequest::class, 'node_id');
    }

    /**
     * Nodes that may be handed work right now.
     *
     * CaptchaNodeFleet::capacity() sums reported_concurrency over exactly this set, so a
     * node that is disabled, stale or paused contributes nothing and the fleet stops being
     * chosen over the paid providers.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('enabled', true)
            ->where('status', 'online')
            ->where('worker_state', '!=', 'paused');
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    public function isPaused(): bool
    {
        return $this->worker_state === 'paused';
    }

    public function publishCommand(string $command): void
    {
        $this->update(['pending_command' => $command]);
    }

    public function clearPendingCommand(): void
    {
        $this->update(['pending_command' => null]);
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
        if ($this->last_heartbeat_at && $this->last_heartbeat_at->diffInSeconds(now()) > self::STALE_AFTER_SECONDS) {
            $this->update(['status' => 'offline', 'active' => 0, 'queued' => 0]);
        }
    }

    /**
     * How many solves this node can run at once, as it last reported.
     */
    public function effectiveConcurrency(): int
    {
        return (int) ($this->reported_concurrency ?? $this->concurrency ?? 0);
    }
}
