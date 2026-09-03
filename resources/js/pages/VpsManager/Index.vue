<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Globe,
    Plus,
    Trash2,
    Copy,
    Eye,
    EyeOff,
    RefreshCw,
    Settings,
    X,
    Check,
    Loader2,
    ArrowUpCircle,
    AlertTriangle,
    Server,
    Pencil,
    Package,
    Cpu,
    Zap,
    Download,
} from 'lucide-vue-next';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useToast } from 'vue-toastification';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface AgentSlot {
    id: number;
    name: string;
    api_key: string;
    status: 'online' | 'offline';
    worker_state: string;
    bot_version: string | null;
}

interface CaptchaNodeRef {
    id: number;
    name: string;
    api_key: string;
    enabled: boolean;
    status: 'online' | 'offline';
    worker_state: string;
    profile: 'dedicated' | 'shared';
    script_version: string | null;
    cpu_cores: number | null;
    reported_concurrency: number | null;
    solved: number;
    failed: number;
    avg_ms: number | null;
    last_heartbeat_at: string | null;
}

interface VpsInstance {
    id: number;
    role: 'bot' | 'captcha';
    provider: string;
    provider_instance_id: string | null;
    instance_name: string;
    public_ip: string | null;
    ssh_username: string | null;
    root_password: string | null;
    status: string;
    status_message: string | null;
    update_status: string | null;
    bot_version: string | null;
    update_available: boolean;
    created_at: string;
    destroyed_at: string | null;
    agent_slot: AgentSlot | null;
    captcha_node?: CaptchaNodeRef | null;
    captcha_status?: string | null;
    captcha_message?: string | null;
    captcha_update_available?: boolean;
}

interface VpsSettings {
    lightnode_api_token: string | null;
    lightnode_region_code: string | null;
    lightnode_zone_code: string | null;
    lightnode_plan_code: string | null;
    lightnode_image_uuid: string | null;
    configured: boolean;
}

interface Region {
    regionCode: string;
    regionName: string;
    zones: { zoneCode: string; zoneName: string }[];
}

interface Plan {
    packageCode: string;
    cpu: number;
    memory: number;
    regionCode: string;
    zoneCode: string;
}

interface LnImage {
    imageResourceUUID: string;
    imageName: string;
    osDistroVersion: string;
    osVersionDetail: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'VPS Manager', href: '#' },
];

const toast = useToast();

// ── State ───────────────────────────────────────────────────────────────────
const instances = ref<VpsInstance[]>([]);
const portalBotVersion = ref<string | null>(null);
const loading = ref(false);
const quantity = ref(1);
const provisioning = ref(false);
const provisionRole = ref<'bot' | 'captcha'>('bot');
// Only meaningful for a captcha node: 'shared' sizes it down and yields CPU to ipms-bot.
const provisionProfile = ref<'dedicated' | 'shared'>('dedicated');
const destroyingId = ref<number | null>(null);
const confirmDestroyId = ref<number | null>(null);
const revealedPasswordIds = ref<Set<number>>(new Set());
const pollingTimer = ref<ReturnType<typeof setInterval> | null>(null);
const updatingAllBots = ref(false);
const updatingBotId = ref<number | null>(null);

// ── Edit credentials ──────────────────────────────────────────────────────
const editingId = ref<number | null>(null);
const editForm = ref({ instance_name: '', ssh_username: '', root_password: '' });
const editSaving = ref(false);
const editRevealPassword = ref(false);

// ── Manual Entry ─────────────────────────────────────────────────────────────
const showAddEntry = ref(false);
const addEntryForm = ref({ public_ip: '', ssh_username: 'root', root_password: '', instance_name: '' });
const addEntryLoading = ref(false);

// ── Settings panel ───────────────────────────────────────────────────────────
const showSettings = ref(false);
const settingsLoading = ref(false);
const settingsSaving = ref(false);
const settings = ref<VpsSettings>({
    lightnode_api_token: null,
    lightnode_region_code: null,
    lightnode_zone_code: null,
    lightnode_plan_code: null,
    lightnode_image_uuid: null,
    configured: false,
});
const settingsTokenInput = ref('');
const settingsRegion = ref('');
const settingsZone = ref('');
const settingsPlan = ref('');
const settingsImage = ref('');

// Discover lists
const regions = ref<Region[]>([]);
const zones = ref<{ zoneCode: string; zoneName: string }[]>([]);
const plans = ref<Plan[]>([]);
const images = ref<LnImage[]>([]);
const discoveringRegions = ref(false);
const discoveringPlans = ref(false);
const discoveringImages = ref(false);

// ── Computed ─────────────────────────────────────────────────────────────────
const summaryTotal = computed(() => instances.value.length);
const summaryOnline = computed(() =>
    instances.value.filter((i) => (i.captcha_node ?? i.agent_slot)?.status === 'online').length,
);
const summaryInstalling = computed(() => instances.value.filter((i) => ['pending', 'creating', 'installing'].includes(i.status)).length);
const summaryFailed = computed(() => instances.value.filter((i) => i.status === 'failed').length);
const summaryUpdatesAvailable = computed(() => instances.value.filter((i) => i.update_available && i.update_status !== 'updating').length);

const needsPolling = computed(() =>
    instances.value.some(
        (i) => ['pending', 'creating', 'installing', 'destroying'].includes(i.status)
            || i.update_status === 'updating'
            // A solver install pulls Node and a ~500 MB Chrome build, so it is the longest
            // thing this page ever waits on.
            || i.captcha_status === 'installing',
    ),
);

// ── Captcha solver tab ───────────────────────────────────────────────────────
type VpsTab = 'instances' | 'captcha';

const tab = ref<VpsTab>('instances');
const captchaBusyId = ref<number | null>(null);
const installingAllCaptcha = ref(false);
const updatingAllCaptcha = ref(false);

const captchaEligible = computed(() => instances.value.filter((i) => i.public_ip && i.status !== 'destroyed'));
const captchaInstalled = computed(() => captchaEligible.value.filter((i) => i.captcha_node));
const captchaOnline = computed(() => captchaInstalled.value.filter((i) => i.captcha_node?.status === 'online'));
const captchaMissing = computed(() => captchaEligible.value.filter((i) => !i.captcha_node));
const captchaInstalling = computed(() => captchaEligible.value.filter((i) => i.captcha_status === 'installing'));
const captchaFailed = computed(() => captchaEligible.value.filter((i) => i.captcha_status === 'install_failed'));
const captchaUpdates = computed(() => captchaInstalled.value.filter((i) => i.captcha_update_available));

const captchaCapacity = computed(() =>
    captchaOnline.value.reduce((sum, i) => sum + (i.captcha_node?.reported_concurrency ?? 0), 0),
);
const captchaSolved = computed(() => captchaInstalled.value.reduce((sum, i) => sum + (i.captcha_node?.solved ?? 0), 0));

// A solve occupies one slot for ~2.5s wall clock, so aggregate concurrency over that is the
// fleet's steady-state ceiling.
const captchaRate = computed(() => (captchaCapacity.value / 2.5).toFixed(1));

const installCaptcha = async (instance: VpsInstance) => {
    captchaBusyId.value = instance.id;
    try {
        await axios.post(`/api/vps/instances/${instance.id}/captcha/install`);
        toast.success(`Installing the solver on ${instance.instance_name}…`);
        await fetchInstances();
        startPolling();
    } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Install could not be queued.');
    } finally {
        captchaBusyId.value = null;
    }
};

const updateCaptcha = async (instance: VpsInstance) => {
    captchaBusyId.value = instance.id;
    try {
        await axios.post(`/api/vps/instances/${instance.id}/captcha/update`);
        toast.success(`Reinstalling the solver on ${instance.instance_name}…`);
        await fetchInstances();
        startPolling();
    } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Update could not be queued.');
    } finally {
        captchaBusyId.value = null;
    }
};

const removeCaptcha = async (instance: VpsInstance) => {
    if (!confirm(`Remove the captcha solver from ${instance.instance_name}? The bot is unaffected.`)) return;

    captchaBusyId.value = instance.id;
    try {
        const { data } = await axios.delete(`/api/vps/instances/${instance.id}/captcha`);
        toast.success(data.reached ? 'Solver removed.' : 'Node deregistered, but the box was unreachable — check it by hand.');
        await fetchInstances();
    } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Remove failed.');
    } finally {
        captchaBusyId.value = null;
    }
};

/**
 * Retune one node's concurrency. Applied live by the node on its next heartbeat (no
 * restart), and stored on the portal so a node restart re-pulls it instead of reverting to
 * whatever its systemd unit was sized to at install time.
 */
const setNodeConcurrency = async (instance: VpsInstance, event: Event) => {
    const node = instance.captcha_node;
    if (!node) return;

    const value = parseInt((event.target as HTMLInputElement).value, 10);
    if (!Number.isFinite(value) || value < 1 || value > 64) return;
    if (value === (node.reported_concurrency ?? node.cpu_cores)) return;

    captchaBusyId.value = instance.id;
    try {
        await axios.patch(`/api/captcha-nodes/${node.id}`, { concurrency: value });
        toast.success(`${node.name} → concurrency ${value}.`);
        await fetchInstances();
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? 'Could not set concurrency.');
    } finally {
        captchaBusyId.value = null;
    }
};

const installCaptchaAll = async () => {
    if (!confirm(`Install the captcha solver on ${captchaMissing.value.length} box(es)? Each pulls ~500 MB of Chrome and takes a few minutes.`)) return;

    installingAllCaptcha.value = true;
    try {
        const { data } = await axios.post('/api/vps/captcha/install-all');
        toast.success(`Queued ${data.queued} install(s).`);
        if (data.skipped?.length) {
            toast.info(`Skipped: ${data.skipped.join(', ')}`, { timeout: 10000 });
        }
        await fetchInstances();
        startPolling();
    } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Batch install failed.');
    } finally {
        installingAllCaptcha.value = false;
    }
};

const updateCaptchaAll = async () => {
    updatingAllCaptcha.value = true;
    try {
        const { data } = await axios.post('/api/vps/captcha/update-all');
        toast.success(data.queued > 0 ? `Queued ${data.queued} update(s).` : 'Every node is already on the current script.');
        await fetchInstances();
        startPolling();
    } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Batch update failed.');
    } finally {
        updatingAllCaptcha.value = false;
    }
};

// ── Fetch ────────────────────────────────────────────────────────────────────
const fetchInstances = async () => {
    try {
        const res = await axios.get('/api/vps/instances');
        instances.value = res.data.instances;
        portalBotVersion.value = res.data.portal_bot_version ?? null;
    } catch {
        // Silently fail during background polling
    }
};

const fetchSettings = async () => {
    settingsLoading.value = true;
    try {
        const res = await axios.get('/api/vps/settings');
        settings.value = res.data;
        settingsTokenInput.value = '';
        settingsRegion.value = settings.value.lightnode_region_code ?? '';
        settingsZone.value = settings.value.lightnode_zone_code ?? '';
        settingsPlan.value = settings.value.lightnode_plan_code ?? '';
        settingsImage.value = settings.value.lightnode_image_uuid ?? '';
    } catch {
        toast.error('Failed to load settings.');
    } finally {
        settingsLoading.value = false;
    }
};

// ── Polling ──────────────────────────────────────────────────────────────────
const startPolling = () => {
    if (pollingTimer.value) return;
    pollingTimer.value = setInterval(async () => {
        await fetchInstances();
        if (!needsPolling.value) {
            stopPolling();
        }
    }, 3000);
};

const stopPolling = () => {
    if (pollingTimer.value) {
        clearInterval(pollingTimer.value);
        pollingTimer.value = null;
    }
};

// ── Actions ──────────────────────────────────────────────────────────────────
const provision = async () => {
    if (quantity.value < 1 || quantity.value > 20) return;
    provisioning.value = true;
    try {
        await axios.post('/api/vps/provision', {
            quantity: quantity.value,
            role: provisionRole.value,
            profile: provisionProfile.value,
        });
        const label = provisionRole.value === 'captcha' ? 'captcha solver node' : 'VPS instance';
        toast.success(`Provisioning ${quantity.value} ${label}${quantity.value > 1 ? 's' : ''}...`);
        await fetchInstances();
        startPolling();
    } catch (err: any) {
        toast.error(err?.response?.data?.error ?? 'Provision failed.');
    } finally {
        provisioning.value = false;
    }
};

const addManualEntry = async () => {
    if (!addEntryForm.value.public_ip || !addEntryForm.value.ssh_username || !addEntryForm.value.root_password) {
        toast.error('IP, SSH username, and password are required.');
        return;
    }
    addEntryLoading.value = true;
    try {
        await axios.post('/api/vps/instances/manual', addEntryForm.value);
        addEntryForm.value = { public_ip: '', ssh_username: 'root', root_password: '', instance_name: '' };
        showAddEntry.value = false;
        await fetchInstances();
        toast.success('Entry added.');
    } catch (err: any) {
        toast.error(err?.response?.data?.message ?? 'Failed to add entry.');
    } finally {
        addEntryLoading.value = false;
    }
};

const destroyInstance = async (id: number) => {
    if (confirmDestroyId.value !== id) {
        confirmDestroyId.value = id;
        setTimeout(() => {
            if (confirmDestroyId.value === id) confirmDestroyId.value = null;
        }, 5000);
        return;
    }

    confirmDestroyId.value = null;
    destroyingId.value = id;
    try {
        await axios.delete(`/api/vps/instances/${id}`);
        toast.success('Destroy queued — VPS will be removed shortly.');
        await fetchInstances();
        startPolling();
    } catch (err: any) {
        toast.error(err?.response?.data?.error ?? 'Destroy failed.');
    } finally {
        destroyingId.value = null;
    }
};

const updateBot = async (instance: VpsInstance) => {
    updatingBotId.value = instance.id;
    try {
        await axios.post(`/api/vps/instances/${instance.id}/update`);
        toast.success(`Update started for ${instance.instance_name}. Bot will restart shortly.`);
        await fetchInstances();
        startPolling();
    } catch (err: any) {
        toast.error(err?.response?.data?.error ?? 'Update failed.');
    } finally {
        updatingBotId.value = null;
    }
};

const updateAllBots = async () => {
    updatingAllBots.value = true;
    try {
        const res = await axios.post('/api/vps/instances/update-all');
        const count = res.data.queued ?? 0;
        if (count === 0) {
            toast.info('All online workers are already up-to-date.');
        } else {
            toast.success(`Update queued for ${count} worker${count !== 1 ? 's' : ''}.`);
            await fetchInstances();
            startPolling();
        }
    } catch (err: any) {
        toast.error(err?.response?.data?.error ?? 'Update all failed.');
    } finally {
        updatingAllBots.value = false;
    }
};

// ── Edit credentials ──────────────────────────────────────────────────────────
const openEdit = (instance: VpsInstance) => {
    editingId.value = instance.id;
    editForm.value = {
        instance_name: instance.instance_name,
        ssh_username: instance.ssh_username ?? 'root',
        root_password: '',
    };
    editRevealPassword.value = false;
};

const cancelEdit = () => {
    editingId.value = null;
    editRevealPassword.value = false;
};

const saveCredentials = async () => {
    if (!editForm.value.root_password) {
        toast.error('Password is required.');
        return;
    }
    editSaving.value = true;
    try {
        const res = await axios.put(`/api/vps/instances/${editingId.value}/credentials`, editForm.value);
        const idx = instances.value.findIndex((i) => i.id === editingId.value);
        if (idx !== -1) instances.value[idx] = res.data;
        editingId.value = null;
        toast.success('Credentials updated.');
    } catch (err: any) {
        toast.error(err?.response?.data?.message ?? 'Failed to save credentials.');
    } finally {
        editSaving.value = false;
    }
};

const copyText = async (text: string, label = 'Copied') => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(label);
    } catch {
        toast.error('Failed to copy to clipboard');
    }
};

const togglePassword = (id: number) => {
    const next = new Set(revealedPasswordIds.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    revealedPasswordIds.value = next;
};

// ── Settings ─────────────────────────────────────────────────────────────────
const openSettings = async () => {
    showSettings.value = true;
    await fetchSettings();
};

const saveSettings = async () => {
    settingsSaving.value = true;
    try {
        const payload: Record<string, string | null> = {
            lightnode_region_code: settingsRegion.value || null,
            lightnode_zone_code: settingsZone.value || null,
            lightnode_plan_code: settingsPlan.value || null,
            lightnode_image_uuid: settingsImage.value || null,
        };
        if (settingsTokenInput.value) {
            payload.lightnode_api_token = settingsTokenInput.value;
        }
        const res = await axios.post('/api/vps/settings', payload);
        settings.value = { ...res.data, configured: !!res.data.lightnode_region_code && !!res.data.lightnode_plan_code && !!res.data.lightnode_image_uuid };
        settingsTokenInput.value = '';
        toast.success('Settings saved.');
    } catch (err: any) {
        toast.error(err?.response?.data?.message ?? 'Failed to save settings.');
    } finally {
        settingsSaving.value = false;
    }
};

const discoverRegions = async () => {
    discoveringRegions.value = true;
    try {
        const res = await axios.get('/api/vps/discover/regions');
        regions.value = res.data.regions ?? [];
        toast.success(`Loaded ${regions.value.length} regions.`);
    } catch (err: any) {
        toast.error(err?.response?.data?.error ?? 'Failed to load regions.');
    } finally {
        discoveringRegions.value = false;
    }
};

const onRegionChange = () => {
    const region = regions.value.find((r) => r.regionCode === settingsRegion.value);
    zones.value = region?.zones ?? [];
    settingsZone.value = zones.value[0]?.zoneCode ?? '';
};

const discoverPlans = async () => {
    if (!settingsRegion.value) {
        toast.error('Select a region first.');
        return;
    }
    await axios.post('/api/vps/settings', { lightnode_region_code: settingsRegion.value, lightnode_zone_code: settingsZone.value });
    discoveringPlans.value = true;
    try {
        const res = await axios.get('/api/vps/discover/plans');
        plans.value = res.data.plans ?? [];
        toast.success(`Loaded ${plans.value.length} plans.`);
    } catch (err: any) {
        toast.error(err?.response?.data?.error ?? 'Failed to load plans.');
    } finally {
        discoveringPlans.value = false;
    }
};

const discoverImages = async () => {
    if (!settingsRegion.value) {
        toast.error('Select a region first.');
        return;
    }
    await axios.post('/api/vps/settings', { lightnode_region_code: settingsRegion.value });
    discoveringImages.value = true;
    try {
        const res = await axios.get('/api/vps/discover/images');
        images.value = res.data.images ?? [];
        toast.success(`Loaded ${images.value.length} images.`);
    } catch (err: any) {
        toast.error(err?.response?.data?.error ?? 'Failed to load images.');
    } finally {
        discoveringImages.value = false;
    }
};

// ── Helpers ──────────────────────────────────────────────────────────────────
const isInProgress = (status: string) => ['pending', 'creating', 'installing', 'destroying'].includes(status);

const sshUser = (instance: VpsInstance) => instance.ssh_username ?? 'root';

const installCommand = (apiKey: string) =>
    `curl -fsSL https://ipms.senda.fit/install.sh | sudo bash -s -- ${apiKey}`;

const captchaInstallCommand = (node: CaptchaNodeRef) =>
    `curl -fsSL https://ipms.senda.fit/captcha-install.sh | sudo bash -s -- ${node.api_key}`
    + (node.profile === 'shared' ? ' --profile shared' : '');

const relativeTime = (isoString: string) => {
    const diff = (Date.now() - new Date(isoString).getTime()) / 1000;
    if (diff < 60) return `${Math.round(diff)}s ago`;
    if (diff < 3600) return `${Math.round(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.round(diff / 3600)}h ago`;
    return `${Math.round(diff / 86400)}d ago`;
};

// ── Lifecycle ─────────────────────────────────────────────────────────────────
// ── Distributable JAR build (mirrors Bot Control's VPS Setup tab) ──
const jarExists = ref(false);
const buildingJar = ref(false);
const buildOutput = ref('');

const fetchJarStatus = async () => {
    try {
        const res = await axios.get('/api/bot/jar-status');
        jarExists.value = res.data.exists;
    } catch { /* silent */ }
};

const buildJar = async () => {
    buildingJar.value = true;
    buildOutput.value = '';
    try {
        const res = await axios.post('/api/bot/package', {}, { timeout: 300000 });
        jarExists.value = true;
        buildOutput.value = res.data.output ?? '';
        toast.success('JAR built — VPS workers can now be installed or updated.');
    } catch (e: any) {
        buildOutput.value = e?.response?.data?.output ?? '';
        toast.error(e?.response?.data?.message ?? 'Build failed. Check output below.');
    } finally {
        buildingJar.value = false;
    }
};

onMounted(async () => {
    loading.value = true;
    await fetchInstances();
    loading.value = false;
    fetchJarStatus();
    if (needsPolling.value) startPolling();
});

onUnmounted(() => stopPolling());
</script>

<template>
    <Head title="VPS Manager" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <!-- Header -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-sky-400 to-blue-600 shadow-sm shadow-sky-500/30">
                        <Globe class="h-5 w-5 text-white" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">VPS Manager</h1>
                        <p class="text-muted-foreground mt-0.5 text-sm">Provision and manage VPS bot workers</p>
                    </div>
                </div>

                <!-- Summary pills -->
                <div class="flex flex-wrap gap-1.5 text-xs">
                    <span class="rounded-full bg-zinc-500/10 px-2 py-0.5 text-zinc-600 dark:text-zinc-400">Total: {{ summaryTotal }}</span>
                    <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-emerald-700 dark:text-emerald-300">Online: {{ summaryOnline }}</span>
                    <span v-if="summaryInstalling > 0" class="rounded-full bg-blue-500/10 px-2 py-0.5 text-blue-700 dark:text-blue-300">Installing: {{ summaryInstalling }}</span>
                    <span v-if="summaryFailed > 0" class="rounded-full bg-red-500/10 px-2 py-0.5 text-red-700 dark:text-red-300">Failed: {{ summaryFailed }}</span>
                    <span v-if="summaryUpdatesAvailable > 0" class="rounded-full bg-amber-500/10 px-2 py-0.5 text-amber-700 dark:text-amber-300">
                        Updates: {{ summaryUpdatesAvailable }}
                    </span>
                    <span v-if="portalBotVersion" class="rounded-full bg-sky-500/10 px-2 py-0.5 font-mono text-sky-700 dark:text-sky-300">
                        Latest: {{ portalBotVersion }}
                    </span>
                </div>

                <div class="ml-auto flex items-center gap-2">
                    <!-- Update All -->
                    <Button
                        v-if="summaryUpdatesAvailable > 0"
                        size="sm"
                        class="gap-1.5 bg-amber-500 text-white hover:bg-amber-600"
                        :disabled="updatingAllBots"
                        @click="updateAllBots"
                    >
                        <Loader2 v-if="updatingAllBots" class="size-3.5 animate-spin" />
                        <ArrowUpCircle v-else class="size-3.5" />
                        Update All ({{ summaryUpdatesAvailable }})
                    </Button>
                    <!-- Build / Rebuild distributable JAR -->
                    <Button
                        size="sm"
                        class="gap-1.5 bg-amber-500 text-white hover:bg-amber-600"
                        :disabled="buildingJar"
                        :title="jarExists ? 'Rebuild the distributable JAR — required after any bot code change before updating VPS workers' : 'No JAR built yet — build it before installing or updating VPS workers'"
                        @click="buildJar"
                    >
                        <Loader2 v-if="buildingJar" class="size-3.5 animate-spin" />
                        <Package v-else class="size-3.5" />
                        {{ buildingJar ? 'Building…' : jarExists ? 'Rebuild JAR' : 'Build JAR' }}
                    </Button>
                    <Button variant="outline" size="sm" class="gap-1.5" @click="showAddEntry = !showAddEntry">
                        <Plus class="size-3.5" />
                        Add Entry
                    </Button>
                    <Button variant="outline" size="sm" class="gap-1.5" @click="openSettings">
                        <Settings class="size-3.5" />
                        Settings
                    </Button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 border-b border-zinc-200 dark:border-zinc-800">
                <button
                    v-for="entry in [
                        { key: 'instances' as const, label: 'Instances', icon: Server, count: summaryTotal },
                        { key: 'captcha' as const, label: 'Captcha Solver', icon: Cpu, count: captchaInstalled.length },
                    ]"
                    :key="entry.key"
                    class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition-colors"
                    :class="tab === entry.key
                        ? 'border-sky-500 text-sky-600 dark:text-sky-400'
                        : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                    @click="tab = entry.key"
                >
                    <component :is="entry.icon" class="h-4 w-4" />
                    {{ entry.label }}
                    <span class="rounded-full bg-zinc-100 px-1.5 text-[10px] dark:bg-zinc-800">{{ entry.count }}</span>
                </button>
            </div>

            <!-- ================= Captcha Solver ================= -->
            <div v-if="tab === 'captcha'" class="flex flex-col gap-4">
                <!-- Summary -->
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Solver installed</p>
                        <p class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">
                            {{ captchaInstalled.length }}<span class="text-sm font-normal text-zinc-400">/{{ captchaEligible.length }}</span>
                        </p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Nodes online</p>
                        <p class="mt-1 text-lg font-bold" :class="captchaOnline.length > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400'">
                            {{ captchaOnline.length }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Capacity</p>
                        <p class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">{{ captchaCapacity }}</p>
                        <p class="text-[11px] text-zinc-400">concurrent solves</p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Est. throughput</p>
                        <p class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">{{ captchaRate }}<span class="text-sm font-normal text-zinc-400">/s</span></p>
                        <p class="text-[11px] text-zinc-400">~{{ Math.round(Number(captchaRate) * 20) }} accounts</p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Solved</p>
                        <p class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">{{ captchaSolved }}</p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Installing</p>
                        <p class="mt-1 text-lg font-bold" :class="captchaInstalling.length > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400'">
                            {{ captchaInstalling.length }}
                        </p>
                        <p v-if="captchaFailed.length > 0" class="text-[11px] text-red-500">{{ captchaFailed.length }} failed</p>
                    </div>
                </div>

                <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-xs text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/40 dark:text-zinc-400">
                    Bot workers run the solver with the <span class="font-medium">shared</span> profile: the installer sizes concurrency from
                    cores <span class="font-medium">and free RAM</span>, caps CPU, and gives <code class="font-mono">ipms-bot</code> the heavier
                    CPU weight, so the bot always wins contention during the booking window. The portal host is excluded — it already runs the
                    solver from its own checkout.
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <Button
                        size="sm"
                        class="gap-1.5"
                        :disabled="installingAllCaptcha || captchaMissing.length === 0"
                        @click="installCaptchaAll"
                    >
                        <Loader2 v-if="installingAllCaptcha" class="size-3.5 animate-spin" />
                        <Download v-else class="size-3.5" />
                        Install on all ({{ captchaMissing.length }})
                    </Button>
                    <Button
                        size="sm"
                        class="gap-1.5 bg-amber-500 text-white hover:bg-amber-600"
                        :disabled="updatingAllCaptcha || captchaUpdates.length === 0"
                        @click="updateCaptchaAll"
                    >
                        <Loader2 v-if="updatingAllCaptcha" class="size-3.5 animate-spin" />
                        <ArrowUpCircle v-else class="size-3.5" />
                        Update all ({{ captchaUpdates.length }})
                    </Button>
                    <a href="/in-house-captcha" class="text-xs text-sky-600 underline dark:text-sky-400">Open the fleet console →</a>
                    <RefreshCw
                        class="ml-auto size-4 cursor-pointer text-zinc-400 hover:text-zinc-600"
                        :class="{ 'animate-spin': loading }"
                        @click="fetchInstances"
                    />
                </div>

                <!-- Per-VPS solver table -->
                <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-zinc-200 text-left text-[11px] text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Instance</th>
                                    <th class="px-3 py-2 font-medium">Solver</th>
                                    <th class="px-3 py-2 font-medium">Node</th>
                                    <th class="px-3 py-2 font-medium">Cores</th>
                                    <th class="px-3 py-2 font-medium">Conc.</th>
                                    <th class="px-3 py-2 font-medium">Solved</th>
                                    <th class="px-3 py-2 font-medium">Avg</th>
                                    <th class="px-3 py-2 font-medium">Script</th>
                                    <th class="px-3 py-2 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-if="captchaEligible.length === 0">
                                    <td colspan="9" class="py-8 text-center text-sm text-zinc-400">
                                        No reachable VPS instances yet.
                                    </td>
                                </tr>
                                <tr
                                    v-for="instance in captchaEligible"
                                    :key="`cap-${instance.id}`"
                                    class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40"
                                >
                                    <td class="px-3 py-2">
                                        <p class="text-[11px] font-semibold text-zinc-900 dark:text-white">{{ instance.instance_name }}</p>
                                        <p class="font-mono text-[10px] text-zinc-400">{{ instance.public_ip }}</p>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span
                                            v-if="instance.captcha_status === 'installing'"
                                            class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-black"
                                        >
                                            <Loader2 class="size-2.5 animate-spin" /> installing
                                        </span>
                                        <span
                                            v-else-if="instance.captcha_status === 'install_failed'"
                                            class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-black"
                                            :title="instance.captcha_message ?? ''"
                                        >failed</span>
                                        <span
                                            v-else-if="instance.captcha_node"
                                            class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                            :class="instance.captcha_node.status === 'online'
                                                ? 'bg-emerald-100 text-black'
                                                : 'bg-zinc-100 text-black'"
                                        >{{ instance.captcha_node.status === 'online' ? instance.captcha_node.worker_state : 'offline' }}</span>
                                        <span v-else class="text-[10px] text-zinc-400">not installed</span>
                                        <p
                                            v-if="instance.captcha_message && instance.captcha_status"
                                            class="mt-0.5 max-w-48 truncate text-[9px] text-zinc-400"
                                            :title="instance.captcha_message"
                                        >{{ instance.captcha_message }}</p>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span v-if="instance.captcha_node" class="font-mono text-[10px] text-zinc-600 dark:text-zinc-300">
                                            {{ instance.captcha_node.name }}
                                        </span>
                                        <span v-else class="text-[10px] text-zinc-400">—</span>
                                        <p v-if="instance.captcha_node" class="text-[9px] text-zinc-400">{{ instance.captcha_node.profile }}</p>
                                    </td>
                                    <td class="px-3 py-2 text-[11px] text-zinc-600 dark:text-zinc-300">{{ instance.captcha_node?.cpu_cores ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        <input
                                            v-if="instance.captcha_node"
                                            type="number"
                                            min="1"
                                            max="64"
                                            :value="instance.captcha_node.reported_concurrency ?? ''"
                                            :disabled="captchaBusyId === instance.id"
                                            title="Applied live on the next heartbeat — no restart, and it survives one"
                                            class="h-6 w-14 rounded border border-zinc-300 bg-white px-1 text-[11px] dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                                            @change="setNodeConcurrency(instance, $event)"
                                        />
                                        <span v-else class="text-[11px] text-zinc-400">—</span>
                                    </td>
                                    <td class="px-3 py-2 text-[11px] text-zinc-600 dark:text-zinc-300">
                                        {{ instance.captcha_node?.solved ?? '—' }}
                                        <span v-if="instance.captcha_node?.failed" class="text-red-500">/{{ instance.captcha_node.failed }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-[11px] text-zinc-600 dark:text-zinc-300">
                                        {{ instance.captcha_node?.avg_ms ? `${instance.captcha_node.avg_ms}ms` : '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <span
                                            v-if="instance.captcha_node?.script_version"
                                            class="rounded px-1.5 py-0.5 font-mono text-[10px]"
                                            :class="instance.captcha_update_available
                                                ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                                                : 'bg-sky-500/10 text-sky-700 dark:text-sky-300'"
                                        >{{ instance.captcha_node.script_version }}</span>
                                        <span v-else class="text-[10px] text-zinc-400">—</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center justify-end gap-1">
                                            <Button
                                                v-if="!instance.captcha_node"
                                                size="sm"
                                                variant="outline"
                                                class="gap-1 text-[10px]"
                                                :disabled="captchaBusyId === instance.id || instance.captcha_status === 'installing'"
                                                @click="installCaptcha(instance)"
                                            >
                                                <Loader2 v-if="captchaBusyId === instance.id" class="size-3 animate-spin" />
                                                <Download v-else class="size-3" />
                                                Install
                                            </Button>
                                            <template v-else>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    class="gap-1 text-[10px]"
                                                    :disabled="captchaBusyId === instance.id || instance.captcha_status === 'installing'"
                                                    title="Re-run captcha-install.sh over SSH"
                                                    @click="updateCaptcha(instance)"
                                                >
                                                    <Loader2 v-if="captchaBusyId === instance.id" class="size-3 animate-spin" />
                                                    <ArrowUpCircle v-else class="size-3" />
                                                    Update
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    class="gap-1 border-red-300 text-[10px] text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
                                                    :disabled="captchaBusyId === instance.id"
                                                    @click="removeCaptcha(instance)"
                                                >
                                                    <Trash2 class="size-3" />
                                                </Button>
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ================= Instances ================= -->
            <template v-if="tab === 'instances'">

            <!-- JAR build status / output -->
            <div v-if="buildingJar || buildOutput" class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
                <div v-if="buildingJar" class="flex items-center gap-2 px-4 py-3 text-[11px] text-zinc-400">
                    <RefreshCw class="h-3 w-3 animate-spin" />
                    Running mvn clean package — this takes ~30 seconds…
                </div>
                <template v-if="buildOutput">
                    <div class="bg-zinc-50 px-4 py-1.5 text-[10px] font-semibold uppercase tracking-widest text-zinc-400 dark:bg-zinc-900">Maven Output</div>
                    <pre class="max-h-48 overflow-x-auto whitespace-pre-wrap bg-zinc-950 px-4 py-3 font-mono text-[10px] text-zinc-400 dark:bg-black">{{ buildOutput }}</pre>
                </template>
            </div>

            <!-- Provision row -->
            <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                <span class="text-sm font-medium">Provision</span>
                <input
                    v-model.number="quantity"
                    type="number"
                    min="1"
                    max="20"
                    class="w-16 rounded-md border border-zinc-300 bg-white px-2 py-1 text-center text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
                <select
                    v-model="provisionRole"
                    class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                >
                    <option value="bot">bot worker{{ quantity !== 1 ? 's' : '' }}</option>
                    <option value="captcha">captcha solver node{{ quantity !== 1 ? 's' : '' }}</option>
                </select>
                <select
                    v-if="provisionRole === 'captcha'"
                    v-model="provisionProfile"
                    class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    title="Shared yields CPU to ipms-bot; dedicated uses the whole box"
                >
                    <option value="dedicated">dedicated</option>
                    <option value="shared">shared</option>
                </select>
                <Button size="sm" class="gap-1.5" :disabled="provisioning" @click="provision">
                    <Loader2 v-if="provisioning" class="size-3.5 animate-spin" />
                    <Plus v-else class="size-3.5" />
                    {{ provisioning ? 'Provisioning…' : 'Provision' }}
                </Button>
                <span v-if="provisionRole === 'captcha'" class="text-[11px] text-zinc-500">
                    A solver wants ≥2 vCPU and ~1&nbsp;GB free disk for Chrome.
                </span>
                <RefreshCw
                    class="ml-auto size-4 cursor-pointer text-zinc-400 hover:text-zinc-600"
                    :class="{ 'animate-spin': loading }"
                    @click="fetchInstances"
                />
            </div>

            <!-- Add Entry form -->
            <div v-if="showAddEntry" class="rounded-lg border border-blue-200 bg-blue-50/50 px-4 py-3 dark:border-blue-800/50 dark:bg-blue-900/10">
                <div class="mb-2.5 flex items-center justify-between">
                    <span class="text-sm font-medium text-blue-700 dark:text-blue-300">Add Manual Entry</span>
                    <button @click="showAddEntry = false"><X class="size-3.5 text-zinc-400 hover:text-zinc-600" /></button>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <div class="flex flex-col gap-1">
                        <label class="text-[10px] font-medium text-zinc-500">Public IP</label>
                        <input
                            v-model="addEntryForm.public_ip"
                            placeholder="1.2.3.4"
                            class="w-36 rounded-md border border-zinc-300 bg-white px-2.5 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-[10px] font-medium text-zinc-500">SSH Username</label>
                        <input
                            v-model="addEntryForm.ssh_username"
                            placeholder="root"
                            class="w-28 rounded-md border border-zinc-300 bg-white px-2.5 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-[10px] font-medium text-zinc-500">Password</label>
                        <input
                            v-model="addEntryForm.root_password"
                            type="password"
                            placeholder="••••••••"
                            class="w-40 rounded-md border border-zinc-300 bg-white px-2.5 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-[10px] font-medium text-zinc-500">Label (optional)</label>
                        <input
                            v-model="addEntryForm.instance_name"
                            placeholder="my-vps"
                            class="w-32 rounded-md border border-zinc-300 bg-white px-2.5 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        />
                    </div>
                    <Button size="sm" class="gap-1.5" :disabled="addEntryLoading" @click="addManualEntry">
                        <Loader2 v-if="addEntryLoading" class="size-3.5 animate-spin" />
                        <Check v-else class="size-3.5" />
                        Save
                    </Button>
                </div>
            </div>

            <!-- Table -->
            <div class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 overflow-hidden">
                <Table>
                    <TableHeader class="bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm border-b border-zinc-200/60 dark:border-zinc-700/60">
                        <TableRow>
                            <TableHead class="pl-3 pr-2 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest border-r w-[3.125rem]">S/N</TableHead>
                            <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Instance</TableHead>
                            <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Version</TableHead>
                            <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">IP / SSH</TableHead>
                            <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Password</TableHead>
                            <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Status</TableHead>
                            <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Slot</TableHead>
                            <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Created</TableHead>
                            <TableHead class="pl-2 pr-3 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest border-l">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <!-- Loading skeleton -->
                        <template v-if="loading">
                            <TableRow v-for="n in 3" :key="n">
                                <TableCell colspan="9">
                                    <div class="h-4 w-full animate-pulse rounded bg-zinc-200 dark:bg-zinc-700" />
                                </TableCell>
                            </TableRow>
                        </template>

                        <!-- Empty -->
                        <TableRow v-else-if="instances.length === 0">
                            <TableCell colspan="9" class="py-8 text-center text-sm text-zinc-400">
                                No VPS instances yet. Enter a quantity above and click Provision, or use Add Entry for a manual server.
                            </TableCell>
                        </TableRow>

                        <!-- Rows -->
                        <template v-else v-for="(instance, idx) in instances" :key="instance.id">
                            <TableRow class="transition-colors hover:bg-zinc-100/50 dark:hover:bg-zinc-800/50">
                                <!-- S/N -->
                                <TableCell class="pl-3 pr-2 py-1.5 text-center text-[10px] text-zinc-400 dark:text-zinc-600 font-mono tabular-nums border-r">
                                    {{ idx + 1 }}
                                </TableCell>

                                <!-- Instance name + icon -->
                                <TableCell class="px-3 py-1.5">
                                    <div class="flex items-center gap-2">
                                        <div
                                            class="p-1.5 rounded shrink-0"
                                            :class="instance.role === 'captcha' ? 'bg-emerald-100 dark:bg-emerald-900/20' : 'bg-sky-100 dark:bg-sky-900/20'"
                                        >
                                            <Server
                                                class="h-3.5 w-3.5"
                                                :class="instance.role === 'captcha' ? 'text-emerald-600 dark:text-emerald-400' : 'text-sky-600 dark:text-sky-400'"
                                            />
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-[11px] font-semibold">{{ instance.instance_name }}</span>
                                            <span class="text-[10px] text-zinc-400">
                                                {{ instance.provider }}
                                                <template v-if="instance.role === 'captcha'">
                                                    · captcha<template v-if="instance.captcha_node"> ({{ instance.captcha_node.profile }})</template>
                                                </template>
                                            </span>
                                        </div>
                                    </div>
                                </TableCell>

                                <!-- Bot Version -->
                                <TableCell class="px-3 py-1.5">
                                    <div class="flex flex-col gap-1">
                                        <span
                                            v-if="instance.bot_version"
                                            class="font-mono text-[10px] px-2 py-0.5 rounded w-fit"
                                            :class="instance.update_available
                                                ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                                                : 'bg-sky-500/10 text-sky-700 dark:text-sky-300'"
                                        >{{ instance.bot_version }}</span>
                                        <span v-else-if="instance.role === 'captcha' && !instance.captcha_node" class="text-[10px] text-zinc-400">no node</span>
                                        <span v-else-if="instance.role !== 'captcha' && !instance.agent_slot" class="text-[10px] text-zinc-400">no worker</span>
                                        <span v-else class="text-[10px] text-zinc-400">—</span>
                                        <Badge v-if="instance.update_available" class="w-fit px-1.5 py-0 text-[9px] bg-amber-500/15 text-amber-700 dark:text-amber-300 border-0">
                                            outdated
                                        </Badge>
                                    </div>
                                </TableCell>

                                <!-- IP / SSH -->
                                <TableCell class="px-3 py-1.5">
                                    <div v-if="instance.public_ip" class="flex flex-col gap-1">
                                        <div class="flex items-center gap-1">
                                            <span class="font-mono text-[11px]">{{ instance.public_ip }}</span>
                                            <Copy
                                                class="size-3 cursor-pointer text-zinc-400 hover:text-zinc-600"
                                                @click="copyText(instance.public_ip!, 'IP copied')"
                                            />
                                        </div>
                                        <!-- SSH command -->
                                        <div class="flex items-center gap-1">
                                            <code class="rounded bg-zinc-100 px-1.5 py-0.5 text-[9px] dark:bg-zinc-800">ssh {{ sshUser(instance) }}@{{ instance.public_ip }}</code>
                                            <Copy
                                                class="size-3 cursor-pointer text-zinc-400 hover:text-zinc-600"
                                                @click="copyText(`ssh ${sshUser(instance)}@${instance.public_ip}`, 'SSH command copied')"
                                            />
                                        </div>
                                        <!-- Install cmd -->
                                        <div v-if="instance.captcha_node" class="flex items-center gap-1">
                                            <code class="rounded bg-zinc-100 px-1.5 py-0.5 text-[9px] dark:bg-zinc-800">captcha-install.sh … {{ instance.captcha_node.api_key.slice(0, 8) }}…</code>
                                            <Copy
                                                class="size-3 cursor-pointer text-zinc-400 hover:text-zinc-600"
                                                @click="copyText(captchaInstallCommand(instance.captcha_node), 'Install command copied')"
                                            />
                                        </div>
                                        <div v-else-if="instance.agent_slot" class="flex items-center gap-1">
                                            <code class="rounded bg-zinc-100 px-1.5 py-0.5 text-[9px] dark:bg-zinc-800">install.sh … {{ instance.agent_slot.api_key.slice(0, 8) }}…</code>
                                            <Copy
                                                class="size-3 cursor-pointer text-zinc-400 hover:text-zinc-600"
                                                @click="copyText(installCommand(instance.agent_slot.api_key), 'Install command copied')"
                                            />
                                        </div>
                                    </div>
                                    <span v-else class="text-zinc-400 text-[10px]">—</span>
                                </TableCell>

                                <!-- Password -->
                                <TableCell class="px-3 py-1.5">
                                    <div v-if="instance.root_password" class="flex items-center gap-1">
                                        <code class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] dark:bg-zinc-800">
                                            {{ revealedPasswordIds.has(instance.id) ? instance.root_password : '••••••••' }}
                                        </code>
                                        <button @click="togglePassword(instance.id)">
                                            <Eye v-if="!revealedPasswordIds.has(instance.id)" class="size-3 text-zinc-400 hover:text-zinc-600" />
                                            <EyeOff v-else class="size-3 text-zinc-400 hover:text-zinc-600" />
                                        </button>
                                        <Copy class="size-3 cursor-pointer text-zinc-400 hover:text-zinc-600" @click="copyText(instance.root_password!, 'Password copied')" />
                                    </div>
                                    <span v-else class="text-[9px] text-red-500 dark:text-red-400">⚠ use Edit</span>
                                </TableCell>

                                <!-- Status -->
                                <TableCell class="px-3 py-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Loader2 v-if="isInProgress(instance.status)" class="size-3 animate-spin text-blue-500" />
                                        <span class="h-2 w-2 rounded-full shrink-0"
                                            :class="instance.status === 'online' ? 'bg-emerald-500 animate-pulse' : instance.status === 'failed' ? 'bg-red-500' : 'bg-zinc-400'"></span>
                                        <span class="text-[10px]"
                                            :class="instance.status === 'online' ? 'text-emerald-600 dark:text-emerald-400 font-medium' : instance.status === 'failed' ? 'text-red-600 dark:text-red-400' : 'text-zinc-500'">
                                            {{ instance.status }}
                                        </span>
                                    </div>
                                    <div v-if="instance.status_message && instance.update_status !== 'updating'" class="mt-0.5 max-w-40 truncate text-[9px] text-zinc-400" :title="instance.status_message">
                                        {{ instance.status_message }}
                                    </div>
                                </TableCell>

                                <!-- Slot -->
                                <TableCell class="px-3 py-1.5">
                                    <div v-if="instance.captcha_node" class="flex flex-col gap-0.5">
                                        <span class="font-mono text-[11px] font-medium">{{ instance.captcha_node.name }}</span>
                                        <div class="flex items-center gap-1">
                                            <span class="h-1.5 w-1.5 rounded-full"
                                                :class="instance.captcha_node.status === 'online' ? 'bg-emerald-500' : 'bg-zinc-400'"></span>
                                            <span class="text-[9px]"
                                                :class="instance.captcha_node.status === 'online' ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-500'">
                                                {{ instance.captcha_node.status }}
                                            </span>
                                            <span class="text-[9px] text-zinc-400">· {{ instance.captcha_node.worker_state }}</span>
                                        </div>
                                        <span v-if="instance.captcha_node.reported_concurrency" class="text-[9px] text-zinc-400">
                                            ×{{ instance.captcha_node.reported_concurrency }} · {{ instance.captcha_node.solved }} solved
                                        </span>
                                    </div>
                                    <div v-else-if="instance.agent_slot" class="flex flex-col gap-0.5">
                                        <span class="font-mono text-[11px] font-medium">{{ instance.agent_slot.name }}</span>
                                        <div class="flex items-center gap-1">
                                            <span class="h-1.5 w-1.5 rounded-full"
                                                :class="instance.agent_slot.status === 'online' ? 'bg-emerald-500' : 'bg-zinc-400'"></span>
                                            <span class="text-[9px]"
                                                :class="instance.agent_slot.status === 'online' ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-500'">
                                                {{ instance.agent_slot.status }}
                                            </span>
                                            <span class="text-[9px] text-zinc-400">· {{ instance.agent_slot.worker_state }}</span>
                                        </div>
                                    </div>
                                    <span v-else class="text-[10px] text-zinc-400">—</span>
                                </TableCell>

                                <!-- Created -->
                                <TableCell class="px-3 py-1.5 text-[10px] text-zinc-400">{{ relativeTime(instance.created_at) }}</TableCell>

                                <!-- Actions -->
                                <TableCell class="pl-2 pr-3 py-1.5 border-l">
                                    <div class="flex items-center justify-center gap-1">
                                        <!-- Update button -->
                                        <button
                                            v-if="(instance.agent_slot || instance.captcha_node) && (instance.update_available || instance.update_status === 'updating')"
                                            :disabled="updatingBotId === instance.id || updatingAllBots || instance.update_status === 'updating'"
                                            :title="instance.role === 'captcha'
                                                ? 'Re-run captcha-install.sh over SSH (a healthy node can self-update from the Fleet tab instead)'
                                                : instance.agent_slot?.worker_state === 'running'
                                                    ? 'Worker is running — update will restart it'
                                                    : 'Update bot to ' + (portalBotVersion ?? 'latest')"
                                            @click="updateBot(instance)"
                                            class="flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium border border-zinc-200 dark:border-zinc-700 transition-all active:scale-95 disabled:cursor-not-allowed hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                            :class="(updatingBotId === instance.id || updatingAllBots || instance.update_status === 'updating')
                                                ? 'text-zinc-300 dark:text-zinc-600 cursor-not-allowed'
                                                : instance.agent_slot?.worker_state === 'running'
                                                    ? 'cursor-pointer text-amber-600 dark:text-amber-400'
                                                    : 'cursor-pointer text-sky-600 dark:text-sky-400'"
                                        >
                                            <Loader2 v-if="updatingBotId === instance.id || updatingAllBots || instance.update_status === 'updating'" class="size-3 animate-spin" />
                                            <AlertTriangle v-else-if="instance.agent_slot?.worker_state === 'running'" class="size-3" />
                                            <ArrowUpCircle v-else class="size-3" />
                                            {{ instance.update_status === 'updating' ? 'Updating' : 'Update' }}
                                        </button>

                                        <!-- Edit credentials -->
                                        <button
                                            :title="'Edit credentials'"
                                            @click="editingId === instance.id ? cancelEdit() : openEdit(instance)"
                                            class="flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium cursor-pointer border border-zinc-200 dark:border-zinc-700 transition-all active:scale-95 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                            :class="editingId === instance.id ? 'text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20' : 'text-zinc-500 dark:text-zinc-400'"
                                        >
                                            <Pencil class="size-3" />
                                            Edit
                                        </button>

                                        <!-- Destroy button -->
                                        <button
                                            v-if="instance.status !== 'destroyed'"
                                            :disabled="destroyingId === instance.id || instance.status === 'destroying'"
                                            @click="destroyInstance(instance.id)"
                                            class="flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium border border-zinc-200 dark:border-zinc-700 transition-all active:scale-95 disabled:cursor-not-allowed hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                            :class="(destroyingId === instance.id || instance.status === 'destroying')
                                                ? 'text-zinc-300 dark:text-zinc-600 cursor-not-allowed'
                                                : 'cursor-pointer text-red-600 dark:text-red-400'"
                                        >
                                            <Loader2 v-if="destroyingId === instance.id" class="size-3 animate-spin" />
                                            <Trash2 v-else class="size-3" />
                                            {{ confirmDestroyId === instance.id ? 'Confirm?' : 'Destroy' }}
                                        </button>
                                    </div>
                                </TableCell>
                            </TableRow>

                            <!-- Edit credentials row -->
                            <TableRow v-if="editingId === instance.id" class="bg-blue-50/50 dark:bg-blue-950/20 border-t border-blue-200/60 dark:border-blue-800/40">
                                <TableCell class="border-r"></TableCell>
                                <TableCell colspan="8" class="px-4 py-3">
                                    <div class="flex flex-wrap items-end gap-3">
                                        <div class="flex flex-col gap-1">
                                            <label class="text-[10px] font-medium text-zinc-500">Label</label>
                                            <input
                                                v-model="editForm.instance_name"
                                                placeholder="VPS name"
                                                class="w-36 rounded-md border border-zinc-300 bg-white px-2.5 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                            />
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <label class="text-[10px] font-medium text-zinc-500">SSH Username</label>
                                            <input
                                                v-model="editForm.ssh_username"
                                                placeholder="root"
                                                class="w-28 rounded-md border border-zinc-300 bg-white px-2.5 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                            />
                                        </div>
                                        <div class="flex flex-col gap-1">
                                            <label class="text-[10px] font-medium text-zinc-500">New Password <span class="text-red-500">*</span></label>
                                            <div class="flex items-center gap-1">
                                                <input
                                                    v-model="editForm.root_password"
                                                    :type="editRevealPassword ? 'text' : 'password'"
                                                    placeholder="Enter SSH password"
                                                    class="w-44 rounded-md border border-zinc-300 bg-white px-2.5 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                                />
                                                <button @click="editRevealPassword = !editRevealPassword" class="text-zinc-400 hover:text-zinc-600">
                                                    <Eye v-if="!editRevealPassword" class="size-4" />
                                                    <EyeOff v-else class="size-4" />
                                                </button>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <Button size="sm" class="gap-1.5" :disabled="editSaving" @click="saveCredentials">
                                                <Loader2 v-if="editSaving" class="size-3.5 animate-spin" />
                                                <Check v-else class="size-3.5" />
                                                Save
                                            </Button>
                                            <Button size="sm" variant="outline" class="gap-1.5" @click="cancelEdit">
                                                <X class="size-3.5" />
                                                Cancel
                                            </Button>
                                        </div>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </template>
                    </TableBody>
                </Table>
            </div>

            </template>
            <!-- ================= /Instances ================= -->
        </div>

        <!-- Settings Panel (slide-over style overlay) -->
        <Teleport to="body">
            <div v-if="showSettings" class="fixed inset-0 z-50 flex justify-end" @click.self="showSettings = false">
                <div class="relative flex w-full max-w-md flex-col gap-4 overflow-y-auto bg-white p-6 shadow-2xl dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold">LightNode Settings</h2>
                        <button @click="showSettings = false"><X class="size-4 text-zinc-400 hover:text-zinc-600" /></button>
                    </div>

                    <div v-if="settingsLoading" class="flex items-center gap-2 text-sm text-zinc-400">
                        <Loader2 class="size-4 animate-spin" /> Loading…
                    </div>

                    <template v-else>
                        <!-- API Token -->
                        <div class="flex flex-col gap-1.5">
                            <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400">API Token</label>
                            <div class="text-[10px] text-zinc-400">
                                Current: <span class="font-mono">{{ settings.lightnode_api_token ?? 'not set' }}</span>
                            </div>
                            <input
                                v-model="settingsTokenInput"
                                type="password"
                                placeholder="Paste new token to update…"
                                class="rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                            />
                        </div>

                        <!-- Region -->
                        <div class="flex flex-col gap-1.5">
                            <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Region</label>
                            <div class="flex gap-2">
                                <select
                                    v-if="regions.length > 0"
                                    v-model="settingsRegion"
                                    class="flex-1 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                    @change="onRegionChange"
                                >
                                    <option v-for="r in regions" :key="r.regionCode" :value="r.regionCode">{{ r.regionName }} ({{ r.regionCode }})</option>
                                </select>
                                <input
                                    v-else
                                    v-model="settingsRegion"
                                    placeholder="e.g. sgp-01"
                                    class="flex-1 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                />
                                <Button size="sm" variant="outline" :disabled="discoveringRegions" @click="discoverRegions">
                                    <Loader2 v-if="discoveringRegions" class="size-3 animate-spin" />
                                    <RefreshCw v-else class="size-3" />
                                </Button>
                            </div>
                        </div>

                        <!-- Zone -->
                        <div class="flex flex-col gap-1.5">
                            <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Zone</label>
                            <select
                                v-if="zones.length > 0"
                                v-model="settingsZone"
                                class="rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                            >
                                <option v-for="z in zones" :key="z.zoneCode" :value="z.zoneCode">{{ z.zoneName }} ({{ z.zoneCode }})</option>
                            </select>
                            <input
                                v-else
                                v-model="settingsZone"
                                placeholder="e.g. sgp-01a"
                                class="rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                            />
                        </div>

                        <!-- Plan -->
                        <div class="flex flex-col gap-1.5">
                            <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Plan (4-core / 8GB)</label>
                            <div class="flex gap-2">
                                <select
                                    v-if="plans.length > 0"
                                    v-model="settingsPlan"
                                    class="flex-1 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                >
                                    <option v-for="p in plans" :key="p.packageCode" :value="p.packageCode">
                                        {{ p.cpu }}vCPU / {{ p.memory }}GB — {{ p.packageCode }}
                                    </option>
                                </select>
                                <input
                                    v-else
                                    v-model="settingsPlan"
                                    placeholder="e.g. SG-8C-16G"
                                    class="flex-1 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                />
                                <Button size="sm" variant="outline" :disabled="discoveringPlans" @click="discoverPlans">
                                    <Loader2 v-if="discoveringPlans" class="size-3 animate-spin" />
                                    <RefreshCw v-else class="size-3" />
                                </Button>
                            </div>
                        </div>

                        <!-- Image -->
                        <div class="flex flex-col gap-1.5">
                            <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Image</label>
                            <div class="flex gap-2">
                                <select
                                    v-if="images.length > 0"
                                    v-model="settingsImage"
                                    class="flex-1 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                >
                                    <option v-for="img in images" :key="img.imageResourceUUID" :value="img.imageResourceUUID">
                                        {{ img.imageName }} {{ img.osVersionDetail }}
                                    </option>
                                </select>
                                <input
                                    v-else
                                    v-model="settingsImage"
                                    placeholder="Image UUID"
                                    class="flex-1 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                />
                                <Button size="sm" variant="outline" :disabled="discoveringImages" @click="discoverImages">
                                    <Loader2 v-if="discoveringImages" class="size-3 animate-spin" />
                                    <RefreshCw v-else class="size-3" />
                                </Button>
                            </div>
                        </div>

                        <Button size="sm" :disabled="settingsSaving" @click="saveSettings">
                            <Loader2 v-if="settingsSaving" class="mr-1.5 size-3.5 animate-spin" />
                            Save Settings
                        </Button>
                    </template>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
