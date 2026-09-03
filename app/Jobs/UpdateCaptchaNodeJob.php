<?php

namespace App\Jobs;

use App\Models\VpsInstance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

/**
 * Re-run captcha-install.sh over SSH on a solver VPS.
 *
 * A healthy node updates its own script off the heartbeat with no SSH at all, so this is
 * for the cases that cannot: a node that is offline or wedged, or a change that reaches
 * past the script into the runtime — a Node.js bump, new Chrome apt dependencies, or a
 * resize that has to rewrite the systemd unit.
 */
class UpdateCaptchaNodeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Wider than the bot's 360s: this can re-download Chrome. */
    public int $timeout = 900;

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

        $node = $instance->captchaNode;

        if (! $node) {
            $instance->update([
                'update_status' => 'update_failed',
                'status_message' => 'No captcha node is attached to this instance.',
            ]);

            return;
        }

        try {
            $ssh = $this->connectWithRetry($instance->public_ip, $instance->ssh_username ?? 'root', $instance->root_password);

            if (! $ssh) {
                throw new \RuntimeException('SSH login failed for '.$instance->public_ip.' — instance unreachable or bad credentials.');
            }

            // Run synchronously and read the real exit status. Backgrounding it raced the SSH
            // teardown on the bot equivalent: the detached job died before it started, so
            // nothing installed while the job still reported success.
            $ssh->setTimeout(840);

            $profile = $node->profile === 'shared' ? ' --profile shared' : '';
            $installUrl = rtrim(config('app.url'), '/').'/captcha-install.sh';
            $installScript = "curl -fsSL {$installUrl} | bash -s -- {$node->api_key}{$profile}";
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

            Log::info("[UpdateCaptchaNodeJob] Updated {$instance->instance_name} ({$instance->public_ip}) → node {$node->name}");
        } catch (\Throwable $e) {
            Log::error("[UpdateCaptchaNodeJob] Failed for {$instance->instance_name}: ".$e->getMessage());
            $instance->update(['update_status' => 'update_failed', 'status_message' => $e->getMessage()]);
        }
    }

    /**
     * Opens an authenticated SSH session, retrying while the daemon comes up. A freshly-provisioned
     * VPS frequently refuses the first connection, so a single attempt yields a false login failure.
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
