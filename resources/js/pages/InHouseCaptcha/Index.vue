<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import {
    AlertTriangle, BarChart3, Check, Circle, Copy, Cpu, Fingerprint, GitBranch, Info, ListChecks, Loader2, Microscope, Pause,
    Play, Plus, RefreshCw, Route, RotateCcw, Server, Sparkles, Timer, Trash2, Zap,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useToast } from 'vue-toastification';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';

const props = defineProps<{ siteKey: string | null; pageUrl: string | null }>();

const toast = useToast();

interface SolverHealth {
    status: 'up' | 'down';
    chrome?: 'up' | 'idle' | 'down';
    message?: string;
    pool?: {
        concurrency: number;
        active: number;
        queued: number;
        max_queue: number;
        solves_since_launch: number;
        browsers?: number;
        browsers_up?: number;
        idle_ms?: number;
        idle_for_s?: number;
        prewarm?: boolean;
        rss_mb?: number;
    };
    stats?: {
        solved: number;
        failed: number;
        attempts: number;
        launches: number;
        avg_ms: number | null;
        uptime_s: number;
        lastError: { at: string; message: string } | null;
    };
}

interface SolveResult {
    token: string;
    ms: number;
    attempts: number;
}

interface PlanStep {
    title: string;
    detail: string;
    done: boolean;
}

interface TraceCall {
    order: number;
    t_ms: number;
    method: string;
    role: 'document' | 'turnstile' | 'challenge' | 'blob' | 'other';
    host: string | null;
    path: string | null;
    status?: number;
    protocol?: string;
    resource_type?: string;
    session: string;
    request_body_length: number;
    response_body_length: number;
}

interface TraceSummary {
    file: string;
    bytes: number;
    captured_at: string | null;
    solved: boolean;
    requests: number;
    challenge_requests: number;
}

/**
 * The build-out plan, kept on the page as a status checklist so what is actually
 * running is never confused with what is still only designed.
 */
const planSteps: PlanStep[] = [
    {
        title: 'Capture a ground-truth trace from the working browser solver',
        detail:
            'Done — POST /api/in-house-captcha/trace captures the whole sequence: 12 requests, 6 on /cdn-cgi/challenge-platform/, with wire headers, bodies, initiator call stacks and ordering. Recording rides the Network domain rather than a widened Fetch pattern, because pausing every request perturbs a flow whose success rate is timing-sensitive; the one body Network cannot return (the iframe bootstrap, handed to an out-of-process frame) is recovered by a single narrow Fetch pause. The widget renders in a cross-origin iframe, so the tracer auto-attaches down the CDP target tree — without that it sees 3 requests and none of the challenge.',
        done: true,
    },
    {
        title: 'Separate the stable paths from the rotating ones',
        detail:
            'Done — settings.turnstile_endpoints now holds the seven deployment constants (branch letter g, api.js asset, av/fb segments, the fo deploy triple, both hosts) plus a path template per leg, synced on every capture with the same well-formed-only merge and last-known-good fallback as ivac_endpoints. The split is measured, not assumed: stability() re-derives across all stored captures and flags anything that moved, which is how the rch segment was caught as a per-session cache-buster rather than a version. cf-ray, the challenge tokens, timestamps and the pat digest are read live and never stored.',
        done: true,
    },
    {
        title: 'Determine which fingerprint fields are actually checked',
        detail:
            'Done — POST /api/in-house-captcha/bisect runs one arm per signal against the live widget. Measured at 6 samples/arm on a 100% baseline: only 3 real signals are checked. navigator.webdriver is rejected. Timezone UTC is rejected (0/6) while Asia/Tokyo passes (6/6) on a host whose real zone is Asia/Dhaka — so it is not geo-matching the IP, Cloudflare treats UTC itself as a datacenter tell. And any platform claim is rejected: not just a bare UA swap, but one with the client hints fully moved to match (userAgentMetadata brands, platform, platformVersion, architecture, bitness) still scores 0/6 — so the cross-check sits BELOW the HTTP layer, in the TLS fingerprint or a JS-observable OS signal Emulation cannot reach. Screen metrics, hardware concurrency, locale and touch are all free. The positive control is load-bearing: the first one used an injected script, which only affects the NEXT document, so it sat inert at 100% and would have certified a blind run as clean.',
        done: true,
    },
    {
        title: 'Execute the challenge VM in Node instead of reimplementing it',
        detail:
            'In progress, and much further along: the challenge now RUNS browserless — it clears Cloudflare\'s browser-support gate, spawns its worker, builds its widget DOM and enumerates the environment — but it still does not issue the flow POST, so no token comes out of this path yet and every production token is still headless Chrome. Transport was settled first and is a green light: turnstile_transport_test.cjs captures the first flow POST inside Chrome, aborts it so the browser never sends it, and replays those exact bytes from Node in the same live session — 8/8 accepted over HTTP/1.1 and HTTP/2, 200 with the full 822 KB interpreter, so there is no TLS gate and a non-browser client telling the truth about itself is fine. What blocked everything after that was the handshake payload, not fingerprinting: the extraParams message the parent sends is not the bare property bag an early capture suggested but a full message carrying event:"extraParams", widgetId and a wPr reconnaissance block describing the parent page, and without the event name the challenge never consumed it, never started, and reported overrunBegin — which reads like a rejection and is only a 10-second watchdog. Fixing it exposed the real gate, a capability probe that builds a Blob, takes an object URL, constructs a Worker from it and terminates it, all inside one try/catch: the stub had been handed Node\'s own URL.createObjectURL by a later assignment, which rejects a non-Node Blob, and the resulting throw surfaced only as reject:unsupported_browser. Past that the failures became environmental and were closed by measurement rather than guesswork — turnstile_dom_capture.cjs snapshots the real iframe\'s 955 interface constructors and 1,259 window properties, with each member\'s kind, its primitive value and whether the interface is constructible, and the emulator materialises what it does not implement itself while merging the missing members into what it does. Stub methods now stringify as [native code] in both realms, since the bootstrap\'s string table contains that literal. Every environment signal probed against the live iframe matches — 71 of 71, up from 53. The remaining gap is behavioural rather than structural: the challenge completes its startup and then waits, so something it expects to be told is not being told to it.',
        done: false,
    },
    {
        title: 'Close the loop and validate against IVAC, not against the parser',
        detail:
            'Perform the final flow POST, extract the token, then prove it by driving a real IVAC sign-in with it. A token that has the right shape and the right length can still be rejected — shape checks are not validation. This is the gate that decides whether the tier works at all.',
        done: false,
    },
    {
        title: 'Add a rotation monitor before trusting it in a window',
        detail:
            'Cloudflare rotates the challenge script continuously, so emulation is fragile in a way the browser tier is not. Mirror captcha-algorithm:auto-refresh: hash the fetched script, re-derive on change, keep last-known-good on failure, and alarm loudly. Without this the tier breaks silently on Cloudflare\'s schedule rather than ours.',
        done: false,
    },
    {
        title: 'Run it alongside Chrome, failing closed to the browser tier',
        detail:
            'Keep the browser solver as the fallback and let emulated solves fall back to it on any error, with a canary comparing both paths to catch silent divergence. The payoff is the whole point: a solve costs ~4 CPU-seconds in Chrome, which is what caps this host at ~2 solves/s and ~40 accounts. Emulation removes Chrome from the hot path, so 100+ accounts stop being a hardware problem.',
        done: false,
    },
];

const health = ref<SolverHealth | null>(null);
const generating = ref(false);
const restarting = ref(false);
const errorMessage = ref<string | null>(null);
const result = ref<SolveResult | null>(null);
const copied = ref(false);

const configured = computed(() => !!props.siteKey && !!props.pageUrl);
/**
 * An idle-reaped pool is healthy, not down — it closes its browsers after a quiet minute and
 * relaunches on the next solve, so the controls must stay enabled.
 */
const online = computed(() => health.value?.status === 'up' && health.value?.chrome !== 'down');

/** Per-attempt success rate over the sidecar's lifetime — the number that shows a widget regression. */
const successRate = computed(() => {
    const s = health.value?.stats;
    if (!s || !s.attempts) return null;
    return Math.round((s.solved / s.attempts) * 100);
});

async function fetchHealth(): Promise<void> {
    try {
        health.value = (await axios.get('/api/in-house-captcha/health')).data;
    } catch (error: any) {
        health.value = error?.response?.data ?? { status: 'down', message: 'Solver unreachable.' };
    }
}

async function generate(): Promise<void> {
    generating.value = true;
    errorMessage.value = null;
    result.value = null;
    copied.value = false;

    try {
        const { data } = await axios.post('/api/in-house-captcha/generate');
        result.value = { token: data.token, ms: data.ms, attempts: data.attempts };
        toast.success(`Token minted in ${(data.ms / 1000).toFixed(1)}s`);
    } catch (error: any) {
        errorMessage.value = error?.response?.data?.message ?? 'Solve failed.';
    } finally {
        generating.value = false;
        fetchHealth();
    }
}

async function restart(): Promise<void> {
    restarting.value = true;

    try {
        await axios.post('/api/in-house-captcha/restart');
        toast.success('Solver browser relaunched');
    } catch (error: any) {
        toast.error(error?.response?.data?.message ?? 'Restart failed.');
    } finally {
        restarting.value = false;
        fetchHealth();
    }
}

interface BisectArm {
    arm: string;
    samples: number;
    solved: number;
    rate: number;
    delta?: number;
    checked?: boolean;
    errors: string[];
}

interface BisectReport {
    captured_at: string;
    samples_per_arm: number;
    baseline: BisectArm;
    arms: BisectArm[];
    checked: string[];
    ignored: string[];
}

interface FlowConstants {
    stored: Record<string, string>;
    session_keys: string[];
    stability: { samples: number; stable: Record<string, string>; volatile: Record<string, string[]> };
}

const flow = ref<FlowConstants | null>(null);

/** Scalars are the rotating constants; the {placeholder} strings are the path templates. */
const flowConstants = computed(() =>
    Object.entries(flow.value?.stored ?? {})
        .filter(([, value]) => !value.includes('{'))
        .sort(([a], [b]) => a.localeCompare(b)),
);

const flowTemplates = computed(() =>
    Object.entries(flow.value?.stored ?? {}).filter(([, value]) => value.includes('{')),
);

const volatileKeys = computed(() => Object.keys(flow.value?.stability.volatile ?? {}));

async function fetchFlow(): Promise<void> {
    try {
        flow.value = (await axios.get('/api/in-house-captcha/flow')).data;
    } catch (error: any) {
        flow.value = null;
    }
}

const bisect = ref<BisectReport | null>(null);
const bisecting = ref(false);

async function fetchBisect(): Promise<void> {
    try {
        bisect.value = (await axios.get('/api/in-house-captcha/bisect')).data.report;
    } catch (error: any) {
        bisect.value = null;
    }
}

/**
 * Minutes of live solving — one arm per signal, run sequentially so local queueing cannot
 * be mistaken for a rejection.
 */
async function runBisect(): Promise<void> {
    bisecting.value = true;

    try {
        const { data } = await axios.post('/api/in-house-captcha/bisect', { samples: 6 });
        bisect.value = data;
        toast.success(`Bisect complete — ${data.checked.length} of ${data.arms.length} signals are checked`);
    } catch (error: any) {
        toast.error(error?.response?.data?.message ?? 'Bisect failed.');
    } finally {
        bisecting.value = false;
    }
}

const traces = ref<TraceSummary[]>([]);
const openTrace = ref<{ file: string; calls: TraceCall[] } | null>(null);
const tracing = ref(false);

async function fetchTraces(): Promise<void> {
    try {
        traces.value = (await axios.get('/api/in-house-captcha/traces')).data.traces ?? [];
    } catch (error: any) {
        traces.value = [];
    }
}

/** Capture a fresh ground-truth trace, then open it so the sequence is immediately visible. */
async function captureTrace(): Promise<void> {
    tracing.value = true;

    try {
        const { data } = await axios.post('/api/in-house-captcha/trace');
        toast.success(`Traced ${data.summary.requests} requests in ${(data.outcome.ms / 1000).toFixed(1)}s`);

        const changed = Object.keys(data.flow?.changed ?? {});
        if (changed.length) {
            toast.info(`Cloudflare rotation adopted: ${changed.join(', ')}`);
        }

        await Promise.all([fetchTraces(), fetchFlow()]);
        await showTrace(data.file);
    } catch (error: any) {
        toast.error(error?.response?.data?.message ?? 'Trace failed.');
    } finally {
        tracing.value = false;
    }
}

async function showTrace(file: string): Promise<void> {
    if (openTrace.value?.file === file) {
        openTrace.value = null;

        return;
    }

    try {
        const { data } = await axios.get(`/api/in-house-captcha/traces/${encodeURIComponent(file)}`);
        openTrace.value = { file, calls: data.trace.calls ?? [] };
    } catch (error: any) {
        toast.error('Could not read that trace.');
    }
}

/** Bodies run to hundreds of KB, so sizes are the useful unit on screen. */
function formatBytes(bytes: number): string {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;

    return `${(bytes / 1024 / 1024).toFixed(2)} MB`;
}

const roleStyles: Record<TraceCall['role'], string> = {
    document: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300',
    turnstile: 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300',
    challenge: 'bg-orange-100 text-orange-700 dark:bg-orange-900/50 dark:text-orange-300',
    blob: 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
    other: 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
};

async function copyToken(): Promise<void> {
    if (!result.value) return;

    try {
        await navigator.clipboard.writeText(result.value.token);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        toast.error('Failed to copy to clipboard');
    }
}

// ---------------------------------------------------------------------------
// Solver fleet
// ---------------------------------------------------------------------------

type NodeCommand = 'update' | 'pause' | 'resume' | 'restart_browsers';

interface FleetNode {
    id: number;
    name: string;
    api_key: string;
    enabled: boolean;
    profile: 'dedicated' | 'shared';
    status: 'online' | 'offline';
    worker_state: 'idle' | 'solving' | 'paused';
    last_heartbeat_at: string | null;
    ip_address: string | null;
    hostname: string | null;
    script_version: string | null;
    cpu_cores: number | null;
    concurrency: number | null;
    reported_concurrency: number | null;
    active: number;
    queued: number;
    solved: number;
    failed: number;
    avg_ms: number | null;
    last_error: string | null;
    update_available: boolean;
}

interface SolveTotals {
    solved: number;
    failed: number;
    success_rate: number | null;
    avg_ms: number | null;
}

interface FleetResponse {
    nodes: FleetNode[];
    script_version: string;
    capacity: number;
    queue_depth: number;
    queue_limit: number;
    provider: { id: number; enabled: boolean } | null;
    install_url: string;
    stats: {
        today: SolveTotals;
        week: SolveTotals;
        total: SolveTotals;
        per_node: { node_id: number | null; name: string; solved: number; failed: number }[];
    } | null;
}

const tab = ref<'fleet' | 'diagnostics'>('fleet');
const fleet = ref<FleetResponse | null>(null);
const addingNode = ref(false);
const newNodeName = ref('');
const newNodeProfile = ref<'dedicated' | 'shared'>('dedicated');
const newNodeConcurrency = ref<number | null>(null);
const busyNodeId = ref<number | null>(null);
const copiedNodeId = ref<number | null>(null);
const testingNodeId = ref<number | null>(null);

const onlineNodes = computed(() => (fleet.value?.nodes ?? []).filter((n) => n.status === 'online'));
const fleetSolved = computed(() => (fleet.value?.nodes ?? []).reduce((sum, n) => sum + n.solved, 0));
const fleetFailed = computed(() => (fleet.value?.nodes ?? []).reduce((sum, n) => sum + n.failed, 0));

const fleetSuccessRate = computed(() => {
    const total = fleetSolved.value + fleetFailed.value;

    return total > 0 ? Math.round((fleetSolved.value / total) * 100) : null;
});

// A solve occupies one slot for ~2.5s wall clock, so aggregate concurrency divided by that
// is the fleet's steady-state ceiling. Deliberately derived rather than measured: it answers
// "how many accounts can this feed" without waiting for a load test.
const estimatedRate = computed(() =>
    fleet.value ? (fleet.value.capacity / 2.5).toFixed(1) : '0.0',
);

const statPeriods = computed(() => [
    { label: 'Today', data: fleet.value?.stats?.today },
    { label: 'Last 7 days', data: fleet.value?.stats?.week },
    { label: 'All time', data: fleet.value?.stats?.total },
]);

function installCommand(node: FleetNode): string {
    const base = fleet.value?.install_url ?? '/captcha-install.sh';
    const profile = node.profile === 'shared' ? ' --profile shared' : '';

    return `curl -fsSL ${base} | sudo bash -s -- ${node.api_key}${profile}`;
}

async function fetchFleet(): Promise<void> {
    try {
        fleet.value = (await axios.get<FleetResponse>('/api/captcha-nodes')).data;
    } catch {
        // Transient — the 5s poll picks it up again.
    }
}

async function addNode(): Promise<void> {
    if (!newNodeName.value.trim()) return;

    const concurrency = Number(newNodeConcurrency.value);

    try {
        await axios.post('/api/captcha-nodes', {
            name: newNodeName.value.trim(),
            profile: newNodeProfile.value,
            // Blank means "size it from the box's core count", which is what the installer
            // does on its own when the portal has no preference.
            concurrency: Number.isFinite(concurrency) && concurrency >= 1 ? concurrency : null,
        });
        newNodeName.value = '';
        newNodeConcurrency.value = null;
        addingNode.value = false;
        await fetchFleet();
        toast.success('Node created — run the install command on the box.');
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? 'Could not create the node.');
    }
}

async function deleteNode(node: FleetNode): Promise<void> {
    if (!confirm(`Delete node "${node.name}"? Work it is holding is requeued automatically.`)) return;

    await axios.delete(`/api/captcha-nodes/${node.id}`);
    await fetchFleet();
    toast.success(`Deleted ${node.name}.`);
}

async function deleteOfflineNodes(): Promise<void> {
    if (!confirm('Delete every offline node?')) return;

    const { data } = await axios.delete('/api/captcha-nodes/offline');
    await fetchFleet();
    toast.success(`Deleted ${data.deleted} offline node(s).`);
}

async function resetStats(): Promise<void> {
    if (!confirm('Clear every solve total and start the fleet from zero? History for removed nodes goes too.')) return;

    try {
        const { data } = await axios.delete('/api/captcha-nodes/stats');
        await fetchFleet();
        toast.success(`Stats cleared. ${data.notified_nodes} node(s) told to reset their counters.`);
    } catch {
        toast.error('Could not clear the stats.');
    }
}

async function sendNodeCommand(node: FleetNode, command: NodeCommand): Promise<void> {
    busyNodeId.value = node.id;

    try {
        await axios.post(`/api/captcha-nodes/${node.id}/command`, { command });
        // Applied on the node's next heartbeat, so the UI cannot confirm it here.
        toast.success(`Queued "${command}" for ${node.name}.`);
        await fetchFleet();
    } catch {
        toast.error(`Could not queue "${command}".`);
    } finally {
        busyNodeId.value = null;
    }
}

async function updateAllNodes(): Promise<void> {
    const { data } = await axios.post('/api/captcha-nodes/command/all', { command: 'update' });
    toast.success(`Update queued for ${data.count} node(s).`);
    await fetchFleet();
}

async function patchNode(node: FleetNode, payload: Record<string, unknown>): Promise<void> {
    busyNodeId.value = node.id;

    try {
        await axios.patch(`/api/captcha-nodes/${node.id}`, payload);
        await fetchFleet();
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? 'Update failed.');
    } finally {
        busyNodeId.value = null;
    }
}

/**
 * Retune a node without touching its systemd unit. The node applies it live on its next
 * heartbeat — resizing its Chrome pool with it — and the portal stores it so a restart
 * re-pulls the value rather than reverting to whatever the unit was sized to at install time.
 */
async function changeConcurrency(node: FleetNode, event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const value = parseInt(input.value, 10);

    if (!Number.isFinite(value) || value < 1 || value > 64) {
        input.value = String(desiredConcurrency(node) ?? '');

        return;
    }

    if (value === node.concurrency) return;

    await patchNode(node, { concurrency: value });

    toast.success(
        node.status === 'online'
            ? `${node.name} retuning to ${value} concurrent solves.`
            : `Saved — ${node.name} applies ${value} when it comes back online.`,
    );
}

/** What the node should be running: the portal's choice, else whatever it last reported. */
function desiredConcurrency(node: FleetNode): number | null {
    return node.concurrency ?? node.reported_concurrency;
}

/** True while the portal's choice has not yet reached the node. */
function concurrencyPending(node: FleetNode): boolean {
    return node.concurrency !== null && node.reported_concurrency !== null && node.concurrency !== node.reported_concurrency;
}

/**
 * Solve one captcha on this node through the real fleet path (enqueue -> lease -> result),
 * so a green result proves the whole chain rather than just that some Chrome can mint.
 */
async function testSolve(node: FleetNode): Promise<void> {
    testingNodeId.value = node.id;

    try {
        const { data } = await axios.post(`/api/captcha-nodes/${node.id}/test-solve`);
        const deadline = Date.now() + 60_000;

        while (Date.now() < deadline) {
            await new Promise((r) => setTimeout(r, 1000));

            const poll = await axios.get(`/api/captcha-nodes/test-solve/${data.request_id}`);

            if (poll.data.status === 'ready') {
                toast.success(`${node.name} solved in ${poll.data.ms ?? '?'}ms.`);

                return;
            }

            if (poll.data.status === 'failed') {
                toast.error(`${node.name}: ${poll.data.message ?? 'solve failed'}`);

                return;
            }
        }

        toast.error(`${node.name} did not answer within 60s.`);
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? 'Test solve could not be queued.');
    } finally {
        testingNodeId.value = null;
        fetchFleet();
    }
}

async function copyInstall(node: FleetNode): Promise<void> {
    await navigator.clipboard.writeText(installCommand(node));
    copiedNodeId.value = node.id;
    setTimeout(() => (copiedNodeId.value = null), 1500);
}

function nodeStateClass(node: FleetNode): string {
    if (node.status !== 'online') return 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400';
    if (node.worker_state === 'paused') return 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300';
    if (node.worker_state === 'solving') return 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300';

    return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300';
}

function timeAgo(iso: string | null): string {
    if (!iso) return 'never';

    const seconds = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 60) return `${seconds}s ago`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;

    return `${Math.floor(seconds / 3600)}h ago`;
}

let ticker: ReturnType<typeof setInterval>;
let fleetTicker: ReturnType<typeof setInterval>;

onMounted(() => {
    fetchHealth();
    fetchFleet();
    fetchTraces();
    fetchFlow();
    fetchBisect();
    ticker = setInterval(fetchHealth, 5000);
    fleetTicker = setInterval(() => tab.value === 'fleet' && fetchFleet(), 5000);
});

onUnmounted(() => {
    clearInterval(ticker);
    clearInterval(fleetTicker);
});

const breadcrumbs = [{ title: 'In-House Captcha', href: '/in-house-captcha' }];
</script>

<template>
    <Head title="In-House Captcha" />

    <AppLayout :breadcrumbs="breadcrumbs" full-width>
        <div class="flex h-full w-full flex-1 flex-col gap-4 p-3 sm:p-4 md:p-6">
            <!-- Header -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-emerald-600 shadow-sm shadow-emerald-500/30">
                        <Fingerprint class="h-6 w-6 text-white" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">In-House Captcha</h1>
                        <p class="text-muted-foreground mt-0.5 text-sm">
                            Self-hosted Turnstile solving &mdash; mint tokens without a third-party provider
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                        :class="onlineNodes.length > 0
                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300'
                            : 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300'"
                    >
                        <span class="h-1.5 w-1.5 rounded-full" :class="onlineNodes.length > 0 ? 'bg-emerald-500' : 'bg-red-500'" />
                        {{ onlineNodes.length }} node{{ onlineNodes.length === 1 ? '' : 's' }} online
                    </span>
                    <Button v-if="tab === 'diagnostics'" size="sm" variant="outline" :disabled="restarting" @click="restart">
                        <RefreshCw class="h-4 w-4" :class="restarting ? 'animate-spin' : ''" />
                        Restart browser
                    </Button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 border-b border-zinc-200 dark:border-zinc-800">
                <button
                    v-for="entry in [
                        { key: 'fleet' as const, label: 'Fleet', icon: Server },
                        { key: 'diagnostics' as const, label: 'Diagnostics (this host)', icon: Microscope },
                    ]"
                    :key="entry.key"
                    class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition-colors"
                    :class="tab === entry.key
                        ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400'
                        : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                    @click="tab = entry.key"
                >
                    <component :is="entry.icon" class="h-4 w-4" />
                    {{ entry.label }}
                </button>
            </div>

            <!-- ================= Fleet ================= -->
            <div v-show="tab === 'fleet'" class="contents">
                <div
                    v-if="fleet && fleet.nodes.length > 0 && onlineNodes.length === 0"
                    class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
                >
                    <AlertTriangle class="mt-0.5 h-5 w-5 flex-shrink-0" />
                    <div>
                        <p class="font-semibold">No solver node is checking in</p>
                        <p class="mt-0.5">Captcha requests fall through to the paid providers until a node comes back.</p>
                    </div>
                </div>

                <div
                    v-if="fleet && fleet.provider && !fleet.provider.enabled"
                    class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
                >
                    <AlertTriangle class="mt-0.5 h-5 w-5 flex-shrink-0" />
                    <p>The in-house provider row is disabled, so the fleet will sit idle. Enable it on <a href="/captcha-providers" class="font-semibold underline">Captcha Providers</a>.</p>
                </div>

                <div
                    v-if="fleet && !fleet.provider"
                    class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
                >
                    <AlertTriangle class="mt-0.5 h-5 w-5 flex-shrink-0" />
                    <p>No in-house provider row exists. Add one on <a href="/captcha-providers" class="font-semibold underline">Captcha Providers</a> before the fleet can be used.</p>
                </div>

                <!-- Fleet summary -->
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Nodes online</p>
                        <p class="mt-1 text-lg font-bold" :class="onlineNodes.length > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500'">
                            {{ onlineNodes.length }}<span class="text-sm font-normal text-zinc-400">/{{ fleet?.nodes.length ?? 0 }}</span>
                        </p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Capacity</p>
                        <p class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">{{ fleet?.capacity ?? 0 }}</p>
                        <p class="text-[11px] text-zinc-400">concurrent solves</p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Est. throughput</p>
                        <p class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">{{ estimatedRate }}<span class="text-sm font-normal text-zinc-400">/s</span></p>
                        <p class="text-[11px] text-zinc-400">~{{ Math.round(Number(estimatedRate) * 20) }} accounts</p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Queued</p>
                        <p class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">
                            {{ fleet?.queue_depth ?? 0 }}<span class="text-sm font-normal text-zinc-400">/{{ fleet?.queue_limit ?? 0 }}</span>
                        </p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Solved</p>
                        <p class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">{{ fleetSolved }}</p>
                        <p class="text-[11px] text-zinc-400">since node restart</p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Success</p>
                        <p class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">
                            {{ fleetSuccessRate === null ? '—' : `${fleetSuccessRate}%` }}
                        </p>
                        <p class="text-[11px] text-zinc-400">since node restart</p>
                    </div>
                </div>

                <!-- Captcha generated: durable totals, unlike the per-node counters above
                     which reset whenever a node's process restarts. -->
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                        <BarChart3 class="h-4 w-4 text-zinc-400" />
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Captcha generated</h2>
                        <span class="text-[11px] text-zinc-400">in-house fleet only</span>
                        <Button
                            size="sm"
                            variant="outline"
                            class="ml-auto border-red-300 text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
                            @click="resetStats"
                        >
                            <RotateCcw class="h-4 w-4" />
                            Reset stats
                        </Button>
                    </div>

                    <div class="grid grid-cols-1 divide-y divide-zinc-200 sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-zinc-800">
                        <div v-for="p in statPeriods" :key="p.label" class="p-4">
                            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ p.label }}</p>
                            <p class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                                {{ (p.data?.solved ?? 0).toLocaleString() }}
                            </p>
                            <div class="mt-1 flex flex-wrap gap-x-3 text-[11px] text-zinc-400">
                                <span>{{ (p.data?.failed ?? 0).toLocaleString() }} failed</span>
                                <span>{{ p.data?.success_rate === null || p.data === undefined ? '—' : `${p.data.success_rate}% success` }}</span>
                                <span v-if="p.data?.avg_ms">{{ (p.data.avg_ms / 1000).toFixed(1) }}s avg</span>
                            </div>
                        </div>
                    </div>

                    <div v-if="(fleet?.stats?.per_node ?? []).length > 0" class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">
                        <p class="mb-2 text-[11px] font-medium text-zinc-500 dark:text-zinc-400">By node, last 7 days</p>
                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="row in fleet?.stats?.per_node ?? []"
                                :key="row.node_id ?? row.name"
                                class="rounded bg-zinc-100 px-2 py-1 text-[11px] text-black font-semibold"
                            >
                                {{ row.name }}
                                <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ row.solved.toLocaleString() }}</span>
                                <span v-if="row.failed > 0" class="text-red-500">/{{ row.failed.toLocaleString() }}</span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Node table -->
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                        <div class="flex items-center gap-2">
                            <Server class="h-4 w-4 text-zinc-400" />
                            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Solver nodes</h2>
                            <span v-if="fleet" class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-black font-semibold">
                                script {{ fleet.script_version }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Button size="sm" variant="outline" @click="fetchFleet">
                                <RefreshCw class="h-4 w-4" />
                                Refresh
                            </Button>
                            <Button size="sm" variant="outline" :disabled="onlineNodes.length === 0" @click="updateAllNodes">
                                <GitBranch class="h-4 w-4" />
                                Update all
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                class="border-red-300 text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
                                @click="deleteOfflineNodes"
                            >
                                <Trash2 class="h-4 w-4" />
                                Delete offline
                            </Button>
                            <Button size="sm" @click="addingNode = !addingNode">
                                <Plus class="h-4 w-4" />
                                Add node
                            </Button>
                        </div>
                    </div>

                    <div v-if="addingNode" class="flex flex-wrap items-center gap-2 border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                        <input
                            v-model="newNodeName"
                            placeholder="Node name (e.g. solver-01)"
                            class="h-9 rounded-md border border-zinc-300 bg-white px-3 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            @keyup.enter="addNode"
                        />
                        <select
                            v-model="newNodeProfile"
                            class="h-9 rounded-md border border-zinc-300 bg-white px-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        >
                            <option value="dedicated">Dedicated (captcha only)</option>
                            <option value="shared">Shared (also runs ipms-bot)</option>
                        </select>
                        <input
                            v-model.number="newNodeConcurrency"
                            type="number"
                            min="1"
                            max="64"
                            placeholder="Concurrency"
                            title="Concurrent solves. Leave blank to size from the box's core count."
                            class="h-9 w-32 rounded-md border border-zinc-300 bg-white px-3 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            @keyup.enter="addNode"
                        />
                        <Button size="sm" :disabled="!newNodeName.trim()" @click="addNode">Create</Button>
                        <Button size="sm" variant="ghost" @click="addingNode = false">Cancel</Button>
                        <p class="w-full text-[11px] text-zinc-500 dark:text-zinc-400">
                            Concurrency is optional &mdash; blank lets the installer size it from cores and RAM. Set it here and the
                            installer sizes CPUQuota, memory and the browser count from your number instead.
                        </p>
                    </div>

                    <div v-if="!fleet || fleet.nodes.length === 0" class="px-4 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                        <Server class="mx-auto h-8 w-8 text-zinc-300 dark:text-zinc-700" />
                        <p class="mt-2 font-medium">No solver nodes yet</p>
                        <p class="mt-0.5">Add one, then run its install command on any VPS &mdash; including this host.</p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[80rem] text-xs">
                            <thead class="border-b border-zinc-200 text-left text-[10px] uppercase tracking-widest text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                                <tr>
                                    <th class="w-[3.125rem] whitespace-nowrap border-r border-zinc-200 py-2 pl-3 pr-2 text-center font-semibold dark:border-zinc-800">S/N</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-semibold">Node</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-semibold">State</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-semibold">Host</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-semibold">Cores</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-semibold">Concurrency</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-semibold">In flight</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-semibold">Solved</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-semibold">Avg</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-semibold">Script</th>
                                    <th class="whitespace-nowrap px-3 py-2 font-semibold">Seen</th>
                                    <th class="whitespace-nowrap px-4 py-2 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-for="(node, index) in fleet.nodes" :key="node.id" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                    <td class="w-[3.125rem] whitespace-nowrap border-r border-zinc-200 py-2.5 pl-3 pr-2 text-center font-mono text-[10px] tabular-nums text-zinc-400 dark:border-zinc-800 dark:text-zinc-600">{{ index + 1 }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <span class="h-2 w-2 flex-shrink-0 rounded-full" :class="node.status === 'online' ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-zinc-600'" />
                                            <div>
                                                <p class="font-medium text-zinc-900 dark:text-white">{{ node.name }}</p>
                                                <p class="text-[10px] text-zinc-400">
                                                    {{ node.profile }}<template v-if="!node.enabled"> &middot; disabled</template>
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5">
                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="nodeStateClass(node)">
                                            {{ node.status === 'online' ? node.worker_state : 'offline' }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-zinc-600 dark:text-zinc-300">
                                        <p>{{ node.hostname ?? '—' }}</p>
                                        <p class="font-mono text-[10px] text-zinc-400">{{ node.ip_address ?? '' }}</p>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-zinc-600 dark:text-zinc-300">{{ node.cpu_cores ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5">
                                        <div class="flex items-center gap-1.5">
                                            <input
                                                type="number"
                                                min="1"
                                                max="64"
                                                :value="desiredConcurrency(node) ?? ''"
                                                :disabled="busyNodeId === node.id"
                                                class="h-7 w-14 rounded border border-zinc-300 bg-white px-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                                                @change="changeConcurrency(node, $event)"
                                            />
                                            <span
                                                v-if="concurrencyPending(node)"
                                                class="rounded bg-amber-100 px-1 py-0.5 text-[10px] font-semibold text-black"
                                                :title="`Node is still running ${node.reported_concurrency} — applies on its next heartbeat`"
                                            >
                                                now {{ node.reported_concurrency }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-zinc-600 dark:text-zinc-300">{{ node.active }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-zinc-600 dark:text-zinc-300">
                                        {{ node.solved }}<span v-if="node.failed > 0" class="text-red-500"> / {{ node.failed }} failed</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-zinc-600 dark:text-zinc-300">{{ node.avg_ms ? `${node.avg_ms}ms` : '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2.5">
                                        <span
                                            v-if="node.update_available"
                                            class="rounded bg-amber-100 px-1.5 py-0.5 font-mono text-[10px] text-black font-semibold"
                                            title="Running an older solver script"
                                        >
                                            {{ node.script_version }} &uarr;
                                        </span>
                                        <span v-else class="font-mono text-[10px] text-zinc-400">{{ node.script_version ?? '—' }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-[10px] text-zinc-400">{{ timeAgo(node.last_heartbeat_at) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2.5">
                                        <div class="flex items-center justify-end gap-1">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                :disabled="node.status !== 'online' || testingNodeId === node.id"
                                                title="Solve one captcha through the real fleet path"
                                                @click="testSolve(node)"
                                            >
                                                <Loader2 v-if="testingNodeId === node.id" class="h-4 w-4 animate-spin" />
                                                <Zap v-else class="h-4 w-4" />
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                :disabled="node.status !== 'online' || busyNodeId === node.id"
                                                :title="node.worker_state === 'paused' ? 'Resume leasing' : 'Stop leasing new work'"
                                                @click="sendNodeCommand(node, node.worker_state === 'paused' ? 'resume' : 'pause')"
                                            >
                                                <Play v-if="node.worker_state === 'paused'" class="h-4 w-4" />
                                                <Pause v-else class="h-4 w-4" />
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                :disabled="node.status !== 'online' || busyNodeId === node.id"
                                                title="Restart the Chrome pool"
                                                @click="sendNodeCommand(node, 'restart_browsers')"
                                            >
                                                <RefreshCw class="h-4 w-4" />
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                :disabled="node.status !== 'online' || busyNodeId === node.id"
                                                title="Download the current solver script and restart"
                                                @click="sendNodeCommand(node, 'update')"
                                            >
                                                <GitBranch class="h-4 w-4" />
                                            </Button>
                                            <Button size="sm" variant="ghost" title="Copy install command" @click="copyInstall(node)">
                                                <Check v-if="copiedNodeId === node.id" class="h-4 w-4 text-emerald-500" />
                                                <Copy v-else class="h-4 w-4" />
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                class="text-red-500 hover:bg-red-50 dark:hover:bg-red-950/40"
                                                title="Delete node"
                                                @click="deleteNode(node)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Install commands -->
                <div v-if="fleet && fleet.nodes.length > 0" class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                        <Cpu class="h-4 w-4 text-zinc-400" />
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Install commands</h2>
                    </div>
                    <div class="space-y-3 p-4">
                        <div v-for="node in fleet.nodes" :key="`install-${node.id}`" class="space-y-1.5">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="node.status === 'online' ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-zinc-600'" />
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ node.name }}</span>
                                    <span class="text-xs text-zinc-400">{{ node.profile }}</span>
                                </div>
                                <Button size="sm" variant="ghost" @click="copyInstall(node)">
                                    <Check v-if="copiedNodeId === node.id" class="h-4 w-4 text-emerald-500" />
                                    <Copy v-else class="h-4 w-4" />
                                    {{ copiedNodeId === node.id ? 'Copied!' : 'Copy command' }}
                                </Button>
                            </div>
                            <pre class="select-all overflow-x-auto rounded-lg bg-zinc-900 p-3 font-mono text-xs text-emerald-300">{{ installCommand(node) }}</pre>
                        </div>

                        <div class="rounded-lg bg-zinc-50 p-3 text-xs text-zinc-600 dark:bg-zinc-800/40 dark:text-zinc-400">
                            <p class="font-medium text-zinc-700 dark:text-zinc-300">What the installer does</p>
                            <ul class="mt-1.5 list-inside list-disc space-y-0.5">
                                <li>Installs the shared libraries headless Chrome needs, plus Node.js 22 LTS</li>
                                <li>Installs puppeteer and its Chrome build into <code class="font-mono">/opt/ipms-captcha</code> (~500&nbsp;MB)</li>
                                <li>Downloads the solver script from this portal and installs systemd <code class="font-mono">ipms-captcha-node</code></li>
                                <li>Sizes concurrency and CPUQuota from the box's core count &mdash; <span class="font-medium">shared</span> yields CPU to <code class="font-mono">ipms-bot</code></li>
                            </ul>
                            <p class="mt-2">The node pulls work outbound, so nothing needs to be exposed on the VPS. Re-run the command to reinstall, or use <span class="font-medium">Update</span> above to push a new script without SSH.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= Diagnostics ================= -->
            <div v-show="tab === 'diagnostics'" class="contents">

            <!-- Offline / unconfigured banners -->
            <div
                v-if="health && health.status === 'down'"
                class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
            >
                <AlertTriangle class="mt-0.5 h-5 w-5 flex-shrink-0" />
                <div>
                    <p class="font-semibold">Solver sidecar is not running</p>
                    <p class="mt-0.5">{{ health.message }}</p>
                    <code class="mt-1.5 block font-mono text-xs">sudo systemctl enable --now ipms-in-house-captcha</code>
                </div>
            </div>

            <div
                v-if="!configured"
                class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
            >
                <AlertTriangle class="mt-0.5 h-5 w-5 flex-shrink-0" />
                <p>Set <span class="font-semibold">captcha_site_key</span> and <span class="font-semibold">captcha_page_url</span> under Settings before solving.</p>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Chrome pool</p>
                    <p
                        class="mt-1 text-lg font-bold"
                        :class="health?.chrome === 'up'
                            ? 'text-emerald-600 dark:text-emerald-400'
                            : health?.chrome === 'idle' ? 'text-zinc-500 dark:text-zinc-400' : 'text-zinc-400'"
                    >
                        {{ health?.pool?.browsers ? `${health.pool.browsers_up ?? 0}/${health.pool.browsers}` : (health?.chrome ?? '—') }}
                    </p>
                    <p v-if="health?.chrome === 'idle'" class="mt-0.5 text-[10px] text-zinc-400 dark:text-zinc-500">
                        reaped after {{ Math.round((health.pool?.idle_ms ?? 0) / 1000) }}s idle &middot; relaunches on demand
                    </p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">In flight</p>
                    <p class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">
                        {{ health?.pool ? `${health.pool.active}/${health.pool.concurrency}` : '—' }}
                    </p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Queued</p>
                    <p class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">{{ health?.pool?.queued ?? '—' }}</p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Solved</p>
                    <p class="mt-1 text-lg font-bold text-emerald-600 dark:text-emerald-400">{{ health?.stats?.solved ?? '—' }}</p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Avg time</p>
                    <p class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">
                        {{ health?.stats?.avg_ms ? `${(health.stats.avg_ms / 1000).toFixed(1)}s` : '—' }}
                    </p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Per-attempt</p>
                    <p class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">
                        {{ successRate !== null ? `${successRate}%` : '—' }}
                    </p>
                </div>
            </div>

            <!-- Solve -->
            <section class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-col gap-4 p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0 text-sm">
                            <p class="font-medium text-zinc-900 dark:text-zinc-100">Solving against</p>
                            <p class="mt-0.5 truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                {{ props.siteKey || 'no site key' }} &middot; {{ props.pageUrl || 'no page url' }}
                            </p>
                        </div>
                        <Button :disabled="generating || !configured" @click="generate">
                            <Loader2 v-if="generating" class="h-4 w-4 animate-spin" />
                            <Sparkles v-else class="h-4 w-4" />
                            {{ generating ? 'Solving…' : 'Generate token' }}
                        </Button>
                    </div>

                    <div
                        v-if="errorMessage"
                        class="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"
                    >
                        <AlertTriangle class="mt-0.5 h-4 w-4 flex-shrink-0" />
                        <span class="break-words">{{ errorMessage }}</span>
                    </div>

                    <div v-if="result" class="rounded-lg border border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-900 dark:bg-emerald-950/30">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-3 text-sm text-emerald-800 dark:text-emerald-300">
                                <span class="inline-flex items-center gap-1.5 font-semibold">
                                    <Check class="h-4 w-4" /> Token minted
                                </span>
                                <span class="inline-flex items-center gap-1 text-xs">
                                    <Timer class="h-3.5 w-3.5" /> {{ (result.ms / 1000).toFixed(2) }}s
                                </span>
                                <span class="text-xs">{{ result.attempts }} attempt{{ result.attempts === 1 ? '' : 's' }}</span>
                                <span class="text-xs">{{ result.token.length }} chars</span>
                            </div>
                            <Button size="sm" variant="outline" @click="copyToken">
                                <Check v-if="copied" class="h-4 w-4" />
                                <Copy v-else class="h-4 w-4" />
                                {{ copied ? 'Copied' : 'Copy' }}
                            </Button>
                        </div>
                        <code class="mt-3 block max-h-28 overflow-y-auto break-all rounded bg-white/70 p-2 font-mono text-xs text-zinc-700 dark:bg-zinc-900/60 dark:text-zinc-300">
                            {{ result.token }}
                        </code>
                    </div>

                    <p
                        v-if="health?.stats?.lastError"
                        class="text-xs text-zinc-500 dark:text-zinc-400"
                    >
                        Last solver error ({{ health.stats.lastError.at }}): {{ health.stats.lastError.message }}
                    </p>
                </div>
            </section>

            <!-- Explainer -->
            <div class="rounded-lg border border-teal-200 bg-teal-50 p-4 text-sm dark:border-teal-900 dark:bg-teal-950/40">
                <div class="flex items-start gap-3">
                    <Info class="mt-0.5 h-5 w-5 flex-shrink-0 text-teal-600 dark:text-teal-400" />
                    <div class="space-y-2 text-teal-800 dark:text-teal-200">
                        <p>
                            Headless Chrome navigates to the real page URL, but the document response is swapped for a synthetic page holding
                            only the widget and Cloudflare's own <span class="font-mono text-xs">api.js</span>. The URL is never changed &mdash;
                            only the body &mdash; so <span class="font-mono text-xs">location.origin</span> still reports the real hostname, and
                            the token is genuinely bound to the site key + hostname pair that
                            <span class="font-mono text-xs">siteverify</span> checks. No request ever reaches IVAC, so IVAC downtime and its
                            notice page are irrelevant here.
                        </p>
                        <p class="text-xs">
                            The booking pipeline uses this solver only when an <span class="font-semibold">In-House</span> provider row is
                            enabled on <a href="/captcha-providers" class="font-semibold underline underline-offset-2">Captcha Providers</a>;
                            <span class="font-mono">SolveCaptchaJob</span> then solves inline instead of submitting a vendor task. This page
                            always bypasses that pipeline and calls the sidecar directly, so it stays a safe way to test the solver.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Ground-truth trace -->
            <section class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center gap-2 border-b border-zinc-100 p-4 dark:border-zinc-800">
                    <Route class="h-4 w-4 text-orange-600 dark:text-orange-400" />
                    <div>
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Ground-truth trace</h2>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                            One instrumented solve, recorded in full &mdash; the specification the emulation tier is built against.
                        </p>
                    </div>
                    <Button
                        class="ml-auto"
                        size="sm"
                        variant="outline"
                        :disabled="tracing || !configured || !online"
                        @click="captureTrace"
                    >
                        <Loader2 v-if="tracing" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                        <Route v-else class="mr-1.5 h-3.5 w-3.5" />
                        {{ tracing ? 'Capturing…' : 'Capture trace' }}
                    </Button>
                </div>

                <p v-if="!traces.length" class="p-4 text-sm text-zinc-500 dark:text-zinc-400">
                    No traces captured yet. A capture holds one browser for the whole challenge sequence and writes ~1&nbsp;MB to
                    <span class="font-mono text-xs">storage/app/captcha/turnstile_traces</span>.
                </p>

                <ul v-else class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <li v-for="trace in traces" :key="trace.file">
                        <button
                            class="flex w-full flex-wrap items-center gap-3 p-3 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                            @click="showTrace(trace.file)"
                        >
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                :class="trace.solved
                                    ? 'bg-emerald-100 text-black'
                                    : 'bg-red-100 text-black'"
                            >
                                {{ trace.solved ? 'solved' : 'failed' }}
                            </span>
                            <span class="font-mono text-xs text-zinc-600 dark:text-zinc-300">{{ trace.captured_at }}</span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ trace.requests }} requests &middot; {{ trace.challenge_requests }} on the challenge path
                            </span>
                            <span class="ml-auto text-xs text-zinc-400 dark:text-zinc-500">{{ formatBytes(trace.bytes) }}</span>
                        </button>

                        <div v-if="openTrace?.file === trace.file" class="overflow-x-auto border-t border-zinc-100 dark:border-zinc-800">
                            <table class="w-full min-w-[52rem] text-xs">
                                <thead class="bg-zinc-50 text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                                    <tr>
                                        <th class="p-2 text-left font-medium">t</th>
                                        <th class="p-2 text-left font-medium">Role</th>
                                        <th class="p-2 text-left font-medium">Method</th>
                                        <th class="p-2 text-left font-medium">Status</th>
                                        <th class="p-2 text-left font-medium">URL</th>
                                        <th class="p-2 text-right font-medium">Req</th>
                                        <th class="p-2 text-right font-medium">Res</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    <tr v-for="call in openTrace.calls" :key="call.order">
                                        <td class="p-2 font-mono text-zinc-400">{{ call.t_ms }}ms</td>
                                        <td class="p-2">
                                            <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold" :class="roleStyles[call.role]">
                                                {{ call.role }}
                                            </span>
                                        </td>
                                        <td class="p-2 font-mono text-zinc-600 dark:text-zinc-300">{{ call.method }}</td>
                                        <td
                                            class="p-2 font-mono"
                                            :class="call.status && call.status >= 400
                                                ? 'text-orange-600 dark:text-orange-400'
                                                : 'text-zinc-500 dark:text-zinc-400'"
                                        >
                                            {{ call.status ?? '—' }}
                                        </td>
                                        <td class="max-w-md truncate p-2 font-mono text-zinc-600 dark:text-zinc-300" :title="`${call.host ?? ''}${call.path ?? ''}`">
                                            {{ call.host }}{{ call.path }}
                                        </td>
                                        <td class="p-2 text-right font-mono text-zinc-500 dark:text-zinc-400">{{ formatBytes(call.request_body_length) }}</td>
                                        <td class="p-2 text-right font-mono text-zinc-500 dark:text-zinc-400">{{ formatBytes(call.response_body_length) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Flow constants: the deployment/session split -->
            <section v-if="flow && flowConstants.length" class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center gap-2 border-b border-zinc-100 p-4 dark:border-zinc-800">
                    <GitBranch class="h-4 w-4 text-blue-600 dark:text-blue-400" />
                    <div>
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Flow constants</h2>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                            What Cloudflare rotates, held in settings so a rotation is a config change &mdash; not a redeploy.
                        </p>
                    </div>
                    <span
                        class="ml-auto inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                        :class="volatileKeys.length
                            ? 'bg-red-100 text-black'
                            : 'bg-emerald-100 text-black'"
                    >
                        <AlertTriangle v-if="volatileKeys.length" class="h-3 w-3" />
                        <Check v-else class="h-3 w-3" />
                        {{ volatileKeys.length ? `${volatileKeys.length} volatile` : 'all stable' }}
                        across {{ flow.stability.samples }} captures
                    </span>
                </div>

                <div class="grid gap-4 p-4 md:grid-cols-2">
                    <div>
                        <p class="mb-2 text-xs font-semibold tracking-wide text-zinc-400 uppercase dark:text-zinc-500">
                            Deployment &mdash; stored
                        </p>
                        <dl class="space-y-1">
                            <div v-for="[key, value] in flowConstants" :key="key" class="flex items-baseline gap-2 text-xs">
                                <dt class="w-28 flex-shrink-0 font-medium text-zinc-500 dark:text-zinc-400">{{ key }}</dt>
                                <dd
                                    class="min-w-0 flex-1 truncate font-mono"
                                    :class="flow.stability.volatile[key]
                                        ? 'text-red-600 dark:text-red-400'
                                        : 'text-zinc-700 dark:text-zinc-200'"
                                    :title="value"
                                >
                                    {{ value }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div>
                        <p class="mb-2 text-xs font-semibold tracking-wide text-zinc-400 uppercase dark:text-zinc-500">
                            Per-session &mdash; read live, never stored
                        </p>
                        <div class="flex flex-wrap gap-1">
                            <span
                                v-for="key in flow.session_keys"
                                :key="key"
                                class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] text-black font-semibold"
                            >
                                {{ key }}
                            </span>
                        </div>
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            These differ on every solve. Storing one would bake a single session's value into the config and break
                            every later attempt.
                        </p>
                    </div>
                </div>

                <div class="border-t border-zinc-100 p-4 dark:border-zinc-800">
                    <p class="mb-2 text-xs font-semibold tracking-wide text-zinc-400 uppercase dark:text-zinc-500">Path templates</p>
                    <ul class="space-y-1">
                        <li v-for="[key, value] in flowTemplates" :key="key" class="flex items-baseline gap-2 text-xs">
                            <span class="w-28 flex-shrink-0 font-medium text-zinc-500 dark:text-zinc-400">{{ key }}</span>
                            <code class="min-w-0 flex-1 truncate font-mono text-zinc-700 dark:text-zinc-200" :title="value">{{ value }}</code>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Fingerprint bisect -->
            <section class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center gap-2 border-b border-zinc-100 p-4 dark:border-zinc-800">
                    <Microscope class="h-4 w-4 text-purple-600 dark:text-purple-400" />
                    <div>
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Fingerprint bisect</h2>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                            Which browser signals Cloudflare actually checks &mdash; measured one mutation at a time, not guessed.
                        </p>
                    </div>
                    <Button
                        class="ml-auto"
                        size="sm"
                        variant="outline"
                        :disabled="bisecting || !configured || !online"
                        @click="runBisect"
                    >
                        <Loader2 v-if="bisecting" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                        <Microscope v-else class="mr-1.5 h-3.5 w-3.5" />
                        {{ bisecting ? 'Running… (minutes)' : 'Run bisect' }}
                    </Button>
                </div>

                <p v-if="!bisect" class="p-4 text-sm text-zinc-500 dark:text-zinc-400">
                    No bisect has been run. A run is
                    <span class="font-mono text-xs">(arms + 1) &times; samples</span> sequential solves against the live widget and
                    takes several minutes.
                </p>

                <template v-else>
                    <div class="flex flex-wrap items-center gap-4 border-b border-zinc-100 px-4 py-2 text-xs dark:border-zinc-800">
                        <span class="text-zinc-500 dark:text-zinc-400">
                            Baseline
                            <span class="font-mono font-semibold text-zinc-700 dark:text-zinc-200">
                                {{ bisect.baseline.solved }}/{{ bisect.baseline.samples }} ({{ bisect.baseline.rate }}%)
                            </span>
                        </span>
                        <span class="text-zinc-500 dark:text-zinc-400">
                            {{ bisect.samples_per_arm }} samples per arm
                        </span>
                        <span class="ml-auto font-mono text-zinc-400 dark:text-zinc-500">{{ bisect.captured_at }}</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[36rem] text-xs">
                            <thead class="bg-zinc-50 text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                                <tr>
                                    <th class="p-2 text-left font-medium">Mutated signal</th>
                                    <th class="p-2 text-left font-medium">Solved</th>
                                    <th class="p-2 text-left font-medium">Rate</th>
                                    <th class="p-2 text-left font-medium">Verdict</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-for="arm in bisect.arms" :key="arm.arm">
                                    <td class="p-2 font-mono text-zinc-700 dark:text-zinc-200">{{ arm.arm }}</td>
                                    <td class="p-2 font-mono text-zinc-500 dark:text-zinc-400">{{ arm.solved }}/{{ arm.samples }}</td>
                                    <td
                                        class="p-2 font-mono font-semibold"
                                        :class="arm.checked ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'"
                                    >
                                        {{ arm.rate }}%
                                    </td>
                                    <td class="p-2">
                                        <span
                                            class="rounded px-1.5 py-0.5 text-[10px] font-semibold"
                                            :class="arm.checked
                                                ? 'bg-red-100 text-black'
                                                : 'bg-zinc-100 text-black'"
                                        >
                                            {{ arm.checked ? 'checked — must reproduce' : 'not checked' }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="border-t border-zinc-100 p-4 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                        An emulator has to reproduce only the
                        <span class="font-semibold text-red-600 dark:text-red-400">{{ bisect.checked.length }}</span> checked
                        signal{{ bisect.checked.length === 1 ? '' : 's' }}; the other {{ bisect.ignored.length }} can be left at
                        whatever the runtime reports.
                    </p>
                </template>
            </section>

            <!-- Implementation checklist -->
            <section class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center gap-2 border-b border-zinc-100 p-4 dark:border-zinc-800">
                    <ListChecks class="h-4 w-4 text-teal-600 dark:text-teal-400" />
                    <div>
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Implementation — Protocol emulation</h2>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                            Minting tokens with no browser in the hot path. The headless-Chrome solver above is live and stays the fallback.
                        </p>
                    </div>
                    <span class="ml-auto flex-shrink-0 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ planSteps.filter((s) => s.done).length }} of {{ planSteps.length }} complete
                    </span>
                </div>
                <ol class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <li v-for="(step, index) in planSteps" :key="step.title" class="flex gap-3 p-4">
                        <span
                            class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                            :class="step.done
                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-300'
                                : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400'"
                        >
                            <Check v-if="step.done" class="h-3.5 w-3.5" />
                            <template v-else>{{ index + 1 }}</template>
                        </span>
                        <div>
                            <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                {{ step.title }}
                                <span
                                    v-if="!step.done"
                                    class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-[10px] font-semibold text-black"
                                >
                                    <Circle class="h-2 w-2" /> Not started
                                </span>
                            </p>
                            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ step.detail }}</p>
                        </div>
                    </li>
                </ol>
            </section>
            </div>
            <!-- ================= /Diagnostics ================= -->
        </div>
    </AppLayout>
</template>
