<?php

namespace App\Jobs;

use App\Exceptions\LightNodeException;
use App\Models\VpsInstance;
use App\Services\LightNodeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

/**
 * Provision a LightNode VPS and install the captcha solver on it.
 *
 * Mirrors ProvisionVpsJob — same create/poll/SSH shape — but runs captcha-install.sh
 * against a CaptchaNode key instead of install.sh against an AgentSlot key. The two are
 * kept as separate jobs rather than one parameterised job because the install steps,
 * timeouts and failure messages differ enough that the branches would outnumber the
 * shared lines.
 */
class ProvisionCaptchaNodeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Longer than the bot's 600s: the installer also downloads puppeteer's Chrome build,
     * which is ~500 MB on top of the Node.js runtime.
     */
    public int $timeout = 900;

    public function __construct(private readonly int $vpsInstanceId)
    {
        $this->onConnection('redis')->onQueue('vps');
    }

    public function handle(LightNodeClient $client): void
    {
        $instance = VpsInstance::find($this->vpsInstanceId);

        if (! $instance || $instance->status !== 'pending') {
            return;
        }

        $node = $instance->captchaNode;

        if (! $node) {
            $instance->update([
                'status' => 'failed',
                'status_message' => 'CaptchaNode was deleted before provisioning started.',
            ]);

            return;
        }

        try {
            $instance->update(['status' => 'creating']);

            $result = $client->createInstance(
                instanceName: $instance->instance_name,
                regionCode: $instance->region_code,
                zoneCode: $instance->zone_code,
                planCode: $instance->plan_code,
                imageUuid: $instance->image_uuid,
                password: $instance->root_password,
            );

            $instance->update(['provider_instance_id' => $result['ecsResourceUUID']]);

            $publicIp = $this->pollForIp($client, $result['ecsResourceUUID']);

            $instance->update(['public_ip' => $publicIp, 'status' => 'installing']);

            $this->runInstallScript($publicIp, $instance->root_password, $node->api_key);

            $instance->update([
                'status' => 'installing',
                'status_message' => 'Install script running on VPS — waiting for the node to check in.',
            ]);
        } catch (LightNodeException $e) {
            Log::error('[ProvisionCaptchaNodeJob] LightNode error for VPS #'.$this->vpsInstanceId.': '.$e->getMessage());
            $instance->update(['status' => 'failed', 'status_message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('[ProvisionCaptchaNodeJob] Unexpected error for VPS #'.$this->vpsInstanceId.': '.$e->getMessage());
            $instance->update(['status' => 'failed', 'status_message' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        VpsInstance::find($this->vpsInstanceId)?->update([
            'status' => 'failed',
            'status_message' => $exception->getMessage(),
        ]);
    }

    /**
     * Poll GET /instance/detail until ecsStatus = STARTED and publicIpAddress is set.
     * Waits up to 180 seconds (36 x 5s).
     *
     * @throws LightNodeException on timeout.
     */
    private function pollForIp(LightNodeClient $client, string $ecsUuid): string
    {
        for ($attempt = 0; $attempt < 36; $attempt++) {
            sleep(5);

            $detail = $client->getInstance($ecsUuid);

            if (
                $detail &&
                ($detail['ecsStatus'] ?? '') === 'STARTED' &&
                ! empty($detail['publicIpAddress'])
            ) {
                return $detail['publicIpAddress'];
            }
        }

        throw new LightNodeException('Instance did not become STARTED with a public IP within 180 seconds.');
    }

    /**
     * SSH in and fire captcha-install.sh. Waits up to 120 seconds for port 22.
     *
     * Backgrounded on purpose: the install pulls Node.js and a Chrome build and routinely
     * outlasts an SSH session. Success is confirmed by the node's first heartbeat, not by
     * this call — which is why the instance is left in 'installing'.
     *
     * @throws \RuntimeException on SSH failure.
     */
    private function runInstallScript(string $ip, string $password, string $nodeApiKey): void
    {
        $connected = false;

        for ($attempt = 0; $attempt < 24; $attempt++) {
            sleep(5);

            try {
                $ssh = new SSH2($ip, 22, 10);

                if ($ssh->login('root', $password)) {
                    $connected = true;
                    break;
                }
            } catch (\Throwable) {
                // Port not yet open — retry.
            }
        }

        if (! $connected) {
            throw new \RuntimeException('Could not connect via SSH to '.$ip.' after 120 seconds.');
        }

        $installUrl = rtrim(config('app.url'), '/').'/captcha-install.sh';
        $command = "curl -fsSL {$installUrl} | sudo bash -s -- {$nodeApiKey} > /var/log/ipms-captcha-install.log 2>&1 &";

        $ssh->exec($command);

        try {
            $ssh->disconnect();
        } catch (\Throwable) {
            // Best-effort: install already fired; a phpseclib disconnect error must not fail provisioning.
        }
    }
}
