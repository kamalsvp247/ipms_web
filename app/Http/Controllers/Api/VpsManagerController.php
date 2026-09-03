<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\LightNodeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ProvisionVpsRequest;
use App\Jobs\DestroyVpsJob;
use App\Jobs\InstallCaptchaOnVpsJob;
use App\Jobs\ProvisionCaptchaNodeJob;
use App\Jobs\ProvisionVpsJob;
use App\Jobs\UpdateCaptchaNodeJob;
use App\Jobs\UpdateVpsBotJob;
use App\Models\AgentSlot;
use App\Models\CaptchaNode;
use App\Models\Setting;
use App\Models\VpsInstance;
use App\Services\LightNodeClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class VpsManagerController extends Controller
{
    public function index(): JsonResponse
    {
        $instances = VpsInstance::with(['agentSlot', 'captchaNode'])
            ->whereNotIn('status', ['destroyed'])
            ->orderByDesc('created_at')
            ->get();

        $slotsByIp = AgentSlot::whereNotNull('ip_address')->get()->keyBy('ip_address');

        $portalBotVersion = $this->readPortalBotVersion();

        return response()->json([
            'instances' => $instances->map(fn (VpsInstance $v) => $this->formatInstance($v, $slotsByIp, $portalBotVersion)),
            'portal_bot_version' => $portalBotVersion,
        ]);
    }

    public function provision(ProvisionVpsRequest $request): JsonResponse
    {
        $setting = Setting::instance();

        if (! $setting->lightnode_api_token) {
            return response()->json(['error' => 'LightNode API token is not configured. Open Settings to add it.'], 422);
        }

        if (! $setting->lightnode_region_code || ! $setting->lightnode_plan_code || ! $setting->lightnode_image_uuid || ! $setting->lightnode_zone_code) {
            return response()->json(['error' => 'LightNode region/zone/plan/image not configured. Use Discover Specs first.'], 422);
        }

        $quantity = $request->integer('quantity');
        $role = $request->input('role', 'bot');
        $profile = $request->input('profile', 'dedicated');
        $created = [];

        // Bot workers and solver nodes get separate name series so the two fleets stay
        // legible in LightNode's console and in the instance list.
        $prefix = $role === 'captcha' ? 'solver-' : 'vps-';

        DB::transaction(function () use ($quantity, $role, $profile, $prefix, $setting, &$created) {
            $maxIndex = VpsInstance::whereNotNull('instance_name')
                ->where('instance_name', 'like', $prefix.'%')
                ->selectRaw('MAX(CAST(SUBSTRING(instance_name, ?) AS UNSIGNED)) as max_idx', [strlen($prefix) + 1])
                ->value('max_idx') ?? 0;

            for ($i = 1; $i <= $quantity; $i++) {
                $name = $prefix.($maxIndex + $i);
                $password = $this->generateRootPassword();

                $ownership = $role === 'captcha'
                    ? ['captcha_node_id' => CaptchaNode::create([
                        'name' => $name,
                        'api_key' => CaptchaNode::generateApiKey(),
                        'profile' => $profile,
                        'status' => 'offline',
                    ])->id]
                    : ['agent_slot_id' => AgentSlot::create([
                        'name' => $name,
                        'api_key' => AgentSlot::generateApiKey(),
                        'status' => 'offline',
                    ])->id];

                $instance = VpsInstance::create([
                    ...$ownership,
                    'role' => $role,
                    'provider' => 'lightnode',
                    'region_code' => $setting->lightnode_region_code,
                    'zone_code' => $setting->lightnode_zone_code,
                    'plan_code' => $setting->lightnode_plan_code,
                    'image_uuid' => $setting->lightnode_image_uuid,
                    'instance_name' => $name,
                    'root_password' => $password,
                    'status' => 'pending',
                ]);

                $role === 'captcha'
                    ? ProvisionCaptchaNodeJob::dispatch($instance->id)
                    : ProvisionVpsJob::dispatch($instance->id);

                $created[] = $instance->load(['agentSlot', 'captchaNode']);
            }
        });

        $slotsByIp = collect();
        $portalBotVersion = $this->readPortalBotVersion();

        return response()->json(array_map(fn ($v) => $this->formatInstance($v, $slotsByIp, $portalBotVersion), $created), 201);
    }

    public function destroy(VpsInstance $vpsInstance): JsonResponse
    {
        if (in_array($vpsInstance->status, ['destroying', 'destroyed'])) {
            return response()->json(['error' => 'Instance is already being destroyed.'], 409);
        }

        $vpsInstance->update(['status' => 'destroying', 'status_message' => null]);

        DestroyVpsJob::dispatch($vpsInstance->id);

        $slotsByIp = AgentSlot::whereNotNull('ip_address')->get()->keyBy('ip_address');

        return response()->json($this->formatInstance($vpsInstance->fresh()->load('agentSlot'), $slotsByIp, $this->readPortalBotVersion()));
    }

    public function storeManual(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $data = $request->validate([
            'public_ip'    => 'required|string|max:45|unique:vps_instances,public_ip',
            'ssh_username' => 'required|string|max:100',
            'root_password' => 'required|string|max:255',
            'instance_name' => 'nullable|string|max:100',
        ]);

        $instance = VpsInstance::create([
            'provider'      => 'manual',
            'public_ip'     => $data['public_ip'],
            'ssh_username'  => $data['ssh_username'],
            'root_password' => $data['root_password'],
            'instance_name' => $data['instance_name'] ?? $data['public_ip'],
            'status'        => 'online',
        ]);

        $slotsByIp = AgentSlot::whereNotNull('ip_address')->get()->keyBy('ip_address');

        return response()->json($this->formatInstance($instance->fresh()->load('agentSlot'), $slotsByIp, $this->readPortalBotVersion()), 201);
    }

    public function updateCredentials(VpsInstance $vpsInstance, Request $request): JsonResponse
    {
        $data = $request->validate([
            'instance_name' => 'nullable|string|max:100',
            'ssh_username'  => 'nullable|string|max:100',
            'root_password' => 'required|string|max:255',
        ]);

        $vpsInstance->update(array_filter($data, fn ($v) => $v !== null));

        $slotsByIp = AgentSlot::whereNotNull('ip_address')->get()->keyBy('ip_address');

        return response()->json($this->formatInstance($vpsInstance->fresh()->load('agentSlot'), $slotsByIp, $this->readPortalBotVersion()));
    }

    public function updateBot(VpsInstance $vpsInstance): JsonResponse
    {
        if (! $vpsInstance->public_ip) {
            return response()->json(['error' => 'No public IP on this instance.'], 422);
        }

        if ($vpsInstance->isCaptchaNode()) {
            if (! $vpsInstance->captchaNode) {
                return response()->json(['error' => 'No captcha node is attached to this instance.'], 422);
            }

            if (! $vpsInstance->root_password) {
                return response()->json(['error' => 'SSH password could not be decrypted — re-enter credentials using the Edit button.'], 422);
            }

            $vpsInstance->update(['update_status' => 'updating', 'status_message' => null]);

            UpdateCaptchaNodeJob::dispatch($vpsInstance->id);

            return response()->json(['queued' => true]);
        }

        $slot = $vpsInstance->agentSlot ?? AgentSlot::where('ip_address', $vpsInstance->public_ip)->first();

        if (! $slot) {
            return response()->json(['error' => 'No matched worker found for this instance. The worker must heartbeat at least once.'], 422);
        }

        if (! $vpsInstance->root_password) {
            return response()->json(['error' => 'SSH password could not be decrypted — re-enter credentials using the Edit button.'], 422);
        }

        $vpsInstance->update(['update_status' => 'updating', 'status_message' => null]);

        UpdateVpsBotJob::dispatch($vpsInstance->id);

        return response()->json(['queued' => true]);
    }

    public function updateAllBots(): JsonResponse
    {
        $portalBotVersion = $this->readPortalBotVersion();

        $portalScriptVersion = $this->readPortalScriptVersion();

        $instances = VpsInstance::with(['agentSlot', 'captchaNode'])
            ->whereNotIn('status', ['destroyed'])
            ->whereNotNull('public_ip')
            ->get();

        $slotsByIp = AgentSlot::whereNotNull('ip_address')->get()->keyBy('ip_address');

        $queued = 0;

        foreach ($instances as $instance) {
            if ($instance->isCaptchaNode()) {
                $node = $instance->captchaNode;

                // A healthy node updates itself off its heartbeat, so SSH is reserved for
                // nodes that cannot: offline, or already on the current script but broken.
                if (! $node || $node->status !== 'online' || ! $instance->root_password) {
                    continue;
                }

                if ($portalScriptVersion && $node->script_version === $portalScriptVersion) {
                    continue;
                }

                $instance->update(['update_status' => 'updating', 'status_message' => null]);

                UpdateCaptchaNodeJob::dispatch($instance->id);

                $queued++;

                continue;
            }

            $slot = $instance->agentSlot ?? ($slotsByIp[$instance->public_ip] ?? null);

            if (! $slot || $slot->status !== 'online') {
                continue;
            }

            if ($portalBotVersion && $slot->bot_version === $portalBotVersion) {
                continue;
            }

            if (! $instance->root_password) {
                continue;
            }

            $instance->update(['update_status' => 'updating', 'status_message' => null]);

            UpdateVpsBotJob::dispatch($instance->id);

            $queued++;
        }

        return response()->json(['queued' => $queued]);
    }

    /**
     * Install (or reinstall) the captcha solver on one existing box.
     *
     * A bot worker gets the shared profile, so the installer sizes concurrency from cores
     * AND free RAM and gives ipms-bot the heavier CPU weight.
     */
    public function installCaptcha(VpsInstance $vpsInstance): JsonResponse
    {
        Gate::authorize('bot.manage');

        if ($error = $this->captchaInstallBlocker($vpsInstance)) {
            return response()->json(['error' => $error], 422);
        }

        InstallCaptchaOnVpsJob::ensureNode($vpsInstance);

        $vpsInstance->update(['captcha_status' => 'installing', 'captcha_message' => 'Queued.']);

        InstallCaptchaOnVpsJob::dispatch($vpsInstance->id);

        return response()->json(['queued' => true]);
    }

    /**
     * Install on every eligible box that does not have it yet.
     */
    public function installCaptchaAll(Request $request): JsonResponse
    {
        Gate::authorize('bot.manage');

        $force = $request->boolean('force');
        $queued = 0;
        $skipped = [];

        foreach (VpsInstance::whereNotIn('status', ['destroyed'])->whereNotNull('public_ip')->get() as $instance) {
            if (! $force && $instance->hasCaptchaSolver() && $instance->captcha_status !== 'install_failed') {
                $skipped[] = $instance->instance_name.' (already installed)';

                continue;
            }

            if ($error = $this->captchaInstallBlocker($instance)) {
                $skipped[] = $instance->instance_name.' ('.$error.')';

                continue;
            }

            InstallCaptchaOnVpsJob::ensureNode($instance);
            $instance->update(['captcha_status' => 'installing', 'captcha_message' => 'Queued.']);
            InstallCaptchaOnVpsJob::dispatch($instance->id);
            $queued++;
        }

        return response()->json(['queued' => $queued, 'skipped' => $skipped]);
    }

    /**
     * Push a new solver script to one box over SSH.
     *
     * A healthy node self-updates off its heartbeat from the fleet console; this exists for
     * the cases that cannot — an offline node, or a change that reaches past the script into
     * the runtime (a Node.js bump, new Chrome deps, a resize that rewrites the unit).
     */
    public function updateCaptcha(VpsInstance $vpsInstance): JsonResponse
    {
        Gate::authorize('bot.manage');

        if (! $vpsInstance->captchaNode) {
            return response()->json(['error' => 'The solver is not installed on this instance.'], 422);
        }

        if (! $vpsInstance->root_password) {
            return response()->json(['error' => 'SSH password could not be decrypted — re-enter credentials with Edit.'], 422);
        }

        $vpsInstance->update(['captcha_status' => 'installing', 'captcha_message' => 'Reinstalling.']);

        InstallCaptchaOnVpsJob::dispatch($vpsInstance->id);

        return response()->json(['queued' => true]);
    }

    public function updateCaptchaAll(): JsonResponse
    {
        Gate::authorize('bot.manage');

        $portalScriptVersion = $this->readPortalScriptVersion();
        $queued = 0;

        foreach (VpsInstance::with('captchaNode')->whereNotIn('status', ['destroyed'])->whereNotNull('captcha_node_id')->get() as $instance) {
            $node = $instance->captchaNode;

            if (! $node || ! $instance->root_password) {
                continue;
            }

            if ($portalScriptVersion && $node->script_version === $portalScriptVersion) {
                continue;
            }

            $instance->update(['captcha_status' => 'installing', 'captcha_message' => 'Reinstalling.']);
            InstallCaptchaOnVpsJob::dispatch($instance->id);
            $queued++;
        }

        return response()->json(['queued' => $queued]);
    }

    /**
     * Remove the solver from a box and drop its node registration.
     */
    public function removeCaptcha(VpsInstance $vpsInstance): JsonResponse
    {
        Gate::authorize('bot.manage');

        if (! $vpsInstance->captchaNode) {
            return response()->json(['error' => 'The solver is not installed on this instance.'], 422);
        }

        $reached = InstallCaptchaOnVpsJob::uninstall($vpsInstance);

        // The registration goes regardless: leaving it would keep counting towards fleet
        // capacity until the heartbeat went stale, and work would be leased to a box that
        // is not going to answer.
        $vpsInstance->captchaNode->delete();
        $vpsInstance->update([
            'captcha_node_id' => null,
            'captcha_status' => null,
            'captcha_message' => $reached ? null : 'Node deregistered, but the box could not be reached to stop the service.',
        ]);

        return response()->json(['removed' => true, 'reached' => $reached]);
    }

    /**
     * Why the solver cannot be installed here, or null if it can.
     */
    private function captchaInstallBlocker(VpsInstance $instance): ?string
    {
        if (! $instance->public_ip) {
            return 'no public IP';
        }

        if (! $instance->root_password) {
            return 'no usable SSH password';
        }

        // The portal already runs the solver from its own checkout as ipms-in-house-captcha.
        // A second copy under /opt would double Chrome on the same box for no capacity.
        if ($this->isPortalHost($instance->public_ip)) {
            return 'this is the portal host — it already runs the solver';
        }

        return null;
    }

    private function isPortalHost(string $ip): bool
    {
        $portalHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! $portalHost) {
            return false;
        }

        $resolved = gethostbyname($portalHost);

        return $resolved === $ip || $ip === request()->server('SERVER_ADDR');
    }

    public function discoverSpecs(LightNodeClient $client): JsonResponse
    {
        try {
            $regions = $client->listRegions();

            return response()->json(['regions' => $regions]);
        } catch (LightNodeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function discoverPlans(LightNodeClient $client): JsonResponse
    {
        $setting = Setting::instance();
        $regionCode = $setting->lightnode_region_code;

        if (! $regionCode) {
            return response()->json(['error' => 'Select a region first.'], 422);
        }

        try {
            $plans = $client->listPlans($regionCode);

            return response()->json(['plans' => $plans]);
        } catch (LightNodeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function discoverImages(LightNodeClient $client): JsonResponse
    {
        $setting = Setting::instance();
        $regionCode = $setting->lightnode_region_code;

        if (! $regionCode) {
            return response()->json(['error' => 'Select a region first.'], 422);
        }

        try {
            $images = $client->listImages($regionCode);

            return response()->json(['images' => $images]);
        } catch (LightNodeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function saveSettings(): JsonResponse
    {
        $data = request()->validate([
            'lightnode_api_token' => 'nullable|string|max:512',
            'lightnode_region_code' => 'nullable|string|max:64',
            'lightnode_zone_code' => 'nullable|string|max:64',
            'lightnode_plan_code' => 'nullable|string|max:128',
            'lightnode_image_uuid' => 'nullable|string|max:128',
        ]);

        Setting::instance()->update($data);

        $setting = Setting::instance();

        return response()->json([
            'lightnode_api_token' => $setting->lightnode_api_token ? Str::mask($setting->lightnode_api_token, '*', 4) : null,
            'lightnode_region_code' => $setting->lightnode_region_code,
            'lightnode_zone_code' => $setting->lightnode_zone_code,
            'lightnode_plan_code' => $setting->lightnode_plan_code,
            'lightnode_image_uuid' => $setting->lightnode_image_uuid,
        ]);
    }

    public function getSettings(): JsonResponse
    {
        $setting = Setting::instance();

        return response()->json([
            'lightnode_api_token' => $setting->lightnode_api_token ? Str::mask($setting->lightnode_api_token, '*', 4) : null,
            'lightnode_region_code' => $setting->lightnode_region_code,
            'lightnode_zone_code' => $setting->lightnode_zone_code,
            'lightnode_plan_code' => $setting->lightnode_plan_code,
            'lightnode_image_uuid' => $setting->lightnode_image_uuid,
            'configured' => (bool) ($setting->lightnode_api_token && $setting->lightnode_region_code && $setting->lightnode_plan_code && $setting->lightnode_image_uuid),
        ]);
    }

    private function formatInstance(VpsInstance $instance, $slotsByIp, ?string $portalBotVersion): array
    {
        if ($instance->isCaptchaNode()) {
            return $this->formatCaptchaInstance($instance);
        }

        $slot = $instance->agentSlot ?? ($instance->public_ip ? ($slotsByIp[$instance->public_ip] ?? null) : null);

        $botVersion = $slot?->bot_version;
        $updateAvailable = $portalBotVersion !== null && $botVersion !== null && $botVersion !== $portalBotVersion;

        return [
            'id' => $instance->id,
            'role' => 'bot',
            ...$this->captchaFields($instance),
            'provider' => $instance->provider,
            'provider_instance_id' => $instance->provider_instance_id,
            'instance_name' => $instance->instance_name,
            'public_ip' => $instance->public_ip,
            'ssh_username' => $instance->ssh_username,
            'root_password' => $instance->root_password,
            'status' => $instance->status,
            'status_message' => $instance->status_message,
            'update_status' => $instance->update_status,
            'bot_version' => $botVersion,
            'update_available' => $updateAvailable,
            'created_at' => $instance->created_at?->toIso8601String(),
            'destroyed_at' => $instance->destroyed_at?->toIso8601String(),
            'agent_slot' => $slot ? [
                'id' => $slot->id,
                'name' => $slot->name,
                'api_key' => $slot->api_key,
                'status' => $slot->status,
                'worker_state' => $slot->worker_state,
                'bot_version' => $slot->bot_version,
            ] : null,
        ];
    }

    /**
     * A solver VPS reports against the captcha_nodes registry, not agent_slots, and its
     * "version" is the content hash of the solver script rather than BotVersion.
     *
     * @return array<string, mixed>
     */
    private function formatCaptchaInstance(VpsInstance $instance): array
    {
        $node = $instance->captchaNode;
        $portalScriptVersion = $this->readPortalScriptVersion();

        return [
            'id' => $instance->id,
            'role' => 'captcha',
            ...$this->captchaFields($instance),
            'provider' => $instance->provider,
            'provider_instance_id' => $instance->provider_instance_id,
            'instance_name' => $instance->instance_name,
            'public_ip' => $instance->public_ip,
            'ssh_username' => $instance->ssh_username,
            'root_password' => $instance->root_password,
            'status' => $instance->status,
            'status_message' => $instance->status_message,
            'update_status' => $instance->update_status,
            'bot_version' => $node?->script_version,
            'update_available' => $node?->script_version !== null && $node->script_version !== $portalScriptVersion,
            'created_at' => $instance->created_at?->toIso8601String(),
            'destroyed_at' => $instance->destroyed_at?->toIso8601String(),
            'agent_slot' => null,
        ];
    }

    /**
     * Solver state for a box, emitted for EVERY instance rather than only role=captcha
     * ones: a bot worker can run the solver alongside ipms-bot, and that co-located case
     * is the common one — the two fleets share hardware.
     *
     * @return array<string, mixed>
     */
    private function captchaFields(VpsInstance $instance): array
    {
        $node = $instance->captchaNode;
        $portalScriptVersion = $this->readPortalScriptVersion();

        return [
            'captcha_status' => $instance->captcha_status,
            'captcha_message' => $instance->captcha_message,
            'captcha_update_available' => $node?->script_version !== null
                && $node->script_version !== $portalScriptVersion,
            'captcha_node' => $node ? [
                'id' => $node->id,
                'name' => $node->name,
                'api_key' => $node->api_key,
                'enabled' => $node->enabled,
                'status' => $node->status,
                'worker_state' => $node->worker_state,
                'profile' => $node->profile,
                'script_version' => $node->script_version,
                'cpu_cores' => $node->cpu_cores,
                'reported_concurrency' => $node->reported_concurrency,
                'solved' => $node->solved,
                'failed' => $node->failed,
                'avg_ms' => $node->avg_ms,
                'last_heartbeat_at' => $node->last_heartbeat_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function readPortalBotVersion(): ?string
    {
        return Setting::instance()->latest_jar_version;
    }

    private function readPortalScriptVersion(): ?string
    {
        $path = app_path('Scripts/in_house_captcha_solver.cjs');

        return is_file($path) ? substr(hash_file('sha256', $path), 0, 12) : null;
    }

    /**
     * Generate a root password that meets LightNode's requirements:
     * 8-30 chars, must contain letters, numbers, and at least one special char from ()~!@#$*-+={}[]:;,.?/
     */
    private function generateRootPassword(): string
    {
        $letters = Str::random(8);
        $digits = substr(str_shuffle('0123456789'), 0, 4);
        $specials = substr(str_shuffle('!@#$*-+=?'), 0, 2);

        return str_shuffle($letters.$digits.$specials);
    }
}
