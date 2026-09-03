<?php

namespace App\Jobs;

use App\Exceptions\LightNodeException;
use App\Models\VpsInstance;
use App\Services\LightNodeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DestroyVpsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(private readonly int $vpsInstanceId)
    {
        $this->onConnection('redis')->onQueue('vps');
    }

    public function handle(LightNodeClient $client): void
    {
        $instance = VpsInstance::find($this->vpsInstanceId);

        if (! $instance) {
            return;
        }

        try {
            if ($instance->provider_instance_id) {
                $client->releaseInstance($instance->provider_instance_id);
            }
        } catch (LightNodeException $e) {
            Log::warning('[DestroyVpsJob] LightNode release error for VPS #'.$this->vpsInstanceId.': '.$e->getMessage());
            // Continue with local cleanup even if the API call fails.
        }

        $slot = $instance->agentSlot;

        if ($slot) {
            $slot->update([
                'status' => 'offline',
                'worker_state' => 'idle',
                'ip_address' => null,
            ]);
        }

        // The solver node is deleted rather than parked: unlike an agent slot it holds no
        // account assignments, and leaving it registered would keep it counting towards
        // fleet capacity until its heartbeat went stale.
        $instance->captchaNode?->delete();

        $instance->update([
            'status' => 'destroyed',
            'status_message' => null,
            'public_ip' => null,
            'provider_instance_id' => null,
            'root_password' => null,
            'destroyed_at' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[DestroyVpsJob] Failed for VPS #'.$this->vpsInstanceId.': '.$exception->getMessage());

        VpsInstance::find($this->vpsInstanceId)?->update([
            'status' => 'failed',
            'status_message' => 'Destroy failed: '.$exception->getMessage(),
        ]);
    }
}
