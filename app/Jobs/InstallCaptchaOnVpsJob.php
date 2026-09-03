<?php

namespace App\Jobs;

use App\Models\CaptchaNode;
use App\Models\VpsInstance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

/**
 * Install (or reinstall) the captcha solver on an existing VPS over SSH.
 *
 * Distinct from ProvisionCaptchaNodeJob, which creates the machine first. This one targets
 * a box that already exists and is usually already a bot worker, so it installs with the
 * shared profile: the installer then sizes concurrency from cores AND free RAM and sets
 * CPUWeight=50 against ipms-bot's 200, so the bot wins contention during the window.
 *
 * Run synchronously with a real exit-status check. Backgrounding it was a bug on the bot
 * equivalent: the detached job died with the SSH teardown while the job reported success.
 */
class InstallCaptchaOnVpsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** The installer pulls Node.js and a ~500 MB Chrome build on a cold box. */
    public int $timeout = 1200;

    public function __construct(private readonly int $vpsInstanceId)
    {
        $this->onConnection('redis')->onQueue('vps');
    }

    public function handle(): void
    {
        $instance = VpsInstance::find($this->vpsInstanceId);

        if (! $instance || $instance->captcha_status !== 'installing') {
            return;
        }

        $node = $instance->captchaNode;

        if (! $node) {
            $instance->update(['captcha_status' => 'install_failed', 'captcha_message' => 'No captcha node attached.']);

            return;
        }

        if (! $instance->public_ip || ! $instance->root_password) {
            $instance->update([
                'captcha_status' => 'install_failed',
                'captcha_message' => 'Missing public IP or SSH password — re-enter credentials with Edit.',
            ]);

            return;
        }

        try {
            $ssh = $this->connectWithRetry($instance->public_ip, $instance->ssh_username ?? 'root', $instance->root_password);

            if (! $ssh) {
                throw new \RuntimeException('SSH login failed — instance unreachable or bad credentials.');
            }

            $ssh->setTimeout(1140);

            $profile = $node->profile === 'shared' ? ' --profile shared' : '';
            $installUrl = rtrim(config('app.url'), '/').'/captcha-install.sh';
            $script = "curl -fsSL {$installUrl} | bash -s -- {$node->api_key}{$profile}";

            $output = (string) $ssh->exec('sudo -n bash -c '.escapeshellarg($script).' 2>&1');
            $exitStatus = $ssh->getExitStatus();

            try {
                $ssh->disconnect();
            } catch (\Throwable) {
                // Best-effort: the install already completed; a disconnect error must not flip it to a failure.
            }

            if ($exitStatus !== 0) {
                throw new \RuntimeException('Install exited '.$exitStatus.': '.trim(mb_substr($output, -400)));
            }

            // Left as 'installing' rather than 'installed': the node's first heartbeat is
            // the only thing that actually proves it works, and the console clears this
            // once the node reports online.
            $instance->update(['captcha_status' => 'installing', 'captcha_message' => 'Installed — waiting for the node to check in.']);

            Log::info("[InstallCaptchaOnVpsJob] Installed on {$instance->instance_name} ({$instance->public_ip}) → node {$node->name}");
        } catch (\Throwable $e) {
            Log::error("[InstallCaptchaOnVpsJob] Failed for {$instance->instance_name}: ".$e->getMessage());
            $instance->update(['captcha_status' => 'install_failed', 'captcha_message' => mb_substr($e->getMessage(), 0, 250)]);
        }
    }

    /**
     * Remove the solver from a box and drop its node registration.
     */
    public static function uninstall(VpsInstance $instance): bool
    {
        if (! $instance->public_ip || ! $instance->root_password) {
            return false;
        }

        try {
            $ssh = new SSH2($instance->public_ip, 22, 15);

            if (! $ssh->login($instance->ssh_username ?? 'root', $instance->root_password)) {
                return false;
            }

            $ssh->setTimeout(120);
            $ssh->exec('sudo -n bash -c '.escapeshellarg(
                'systemctl disable --now ipms-captcha-node 2>/dev/null; '
                .'rm -f /etc/systemd/system/ipms-captcha-node.service; '
                .'systemctl daemon-reload; rm -rf /opt/ipms-captcha'
            ).' 2>&1');

            try {
                $ssh->disconnect();
            } catch (\Throwable) {
                // Best-effort.
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning("[InstallCaptchaOnVpsJob] Uninstall failed for {$instance->instance_name}: ".$e->getMessage());

            return false;
        }
    }

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
            'captcha_status' => 'install_failed',
            'captcha_message' => mb_substr($exception->getMessage(), 0, 250),
        ]);
    }

    /**
     * Attach a solver node to this box, reusing one if it already has it.
     */
    public static function ensureNode(VpsInstance $instance): CaptchaNode
    {
        if ($instance->captchaNode) {
            return $instance->captchaNode;
        }

        $base = $instance->instance_name ?: ('vps-'.$instance->id);
        $name = $base.'-solver';
        $suffix = 2;

        while (CaptchaNode::where('name', $name)->exists()) {
            $name = $base.'-solver-'.$suffix++;
        }

        $node = CaptchaNode::create([
            'name' => $name,
            'api_key' => CaptchaNode::generateApiKey(),
            'profile' => $instance->captchaProfile(),
            'status' => 'offline',
        ]);

        $instance->update(['captcha_node_id' => $node->id]);

        return $node;
    }
}
