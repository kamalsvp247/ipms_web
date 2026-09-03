<?php

namespace App\Jobs;

use App\Models\AgentSlot;
use App\Models\VpsInstance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

class UpdateVpsBotJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    public function __construct(private readonly int $vpsInstanceId)
    {
        $this->onConnection('redis')->onQueue('vps');
    }

    public function handle(): void
    {
        $instance = VpsInstance::find($this->vpsInstanceId);

        if (! $instance || $instance->update_status !== 'updating') {
            return;
        }

        $slot = $instance->agentSlot ?? AgentSlot::where('ip_address', $instance->public_ip)->first();

        if (! $slot) {
            $instance->update(['update_status' => 'update_failed', 'status_message' => 'No matched worker found by IP.']);

            return;
        }

        try {
            $ssh = $this->connectWithRetry($instance->public_ip, $instance->ssh_username ?? 'root', $instance->root_password);

            if (! $ssh) {
                throw new \RuntimeException('SSH login failed for '.$instance->public_ip.' — instance unreachable or bad credentials.');
            }

            // install.sh is self-contained — it downloads the latest JAR, rewrites the systemd
            // unit, and restarts ipms-bot on its own. Run it synchronously and read the real exit
            // status. Backgrounding it (nohup ... &) and disconnecting immediately raced the SSH
            // teardown: the detached job died before it started, so nothing installed while the
            // job still reported success and the worker stayed on the old version.
            $ssh->setTimeout(300);

            $installScript = 'curl -fsSL https://ipms.senda.fit/install.sh | bash -s -- '.$slot->api_key;
            $command = 'sudo -n bash -c '.escapeshellarg($installScript).' 2>&1';

            $output = (string) $ssh->exec($command);
            $exitStatus = $ssh->getExitStatus();

            try {
                $ssh->disconnect();
            } catch (\Throwable) {
                // Best-effort: the install has already completed; a disconnect error must not flip a success to failure.
            }

            if ($exitStatus !== 0) {
                throw new \RuntimeException('Install script exited '.$exitStatus.': '.trim(mb_substr($output, -600)));
            }

            $instance->update(['update_status' => null, 'status_message' => null]);

            Log::info("[UpdateVpsBotJob] Updated {$instance->instance_name} ({$instance->public_ip}) → slot {$slot->name}");
        } catch (\Throwable $e) {
            Log::error("[UpdateVpsBotJob] Failed for {$instance->instance_name}: ".$e->getMessage());
            $instance->update(['update_status' => 'update_failed', 'status_message' => $e->getMessage()]);
        }
    }

    /**
     * Opens an authenticated SSH session, retrying while the daemon comes up. A freshly-provisioned
     * VPS frequently refuses the first connection, so a single attempt yields a false login failure.
     * Returns the connected session, or null if every attempt fails.
     */
    private function connectWithRetry(string $ip, string $username, string $password): ?SSH2
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            if ($attempt > 0) {
                sleep(5);
            }

            try {
                $ssh = new SSH2($ip, 22, 15);
                if ($ssh->login($username, $password)) {
                    return $ssh;
                }
            } catch (\Throwable) {
                // Daemon not ready yet; retry after the backoff.
            }
        }

        return null;
    }

    public function failed(\Throwable $exception): void
    {
        VpsInstance::find($this->vpsInstanceId)?->update([
            'update_status' => 'update_failed',
            'status_message' => $exception->getMessage(),
        ]);
    }
}
