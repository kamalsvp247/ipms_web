<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { Network, Plus, Pencil, Trash2, Wifi, X, Check, Filter, ExternalLink, Loader2, ScanLine, Cloud, Globe, Pause, Play, Terminal, AlertTriangle, Eraser, Undo2, Crosshair } from 'lucide-vue-next';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useToast } from 'vue-toastification';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface BypassIp {
    id: number;
    label: string;
    ip: string;
    last_ping_ms: number | null;
    last_pinged_at: string | null;
    response_status: number | null;
    response_message: string | null;
    response_flag: boolean | null;
    response_time_ms: number | null;
    slots_count: number;
    slots: { id: number; name: string }[];
}

interface AgentSlot {
    id: number;
    name: string;
    bypass_ip_id: number | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Bypass IPs', href: '#' },
];

const toast = useToast();

const bypassIps = ref<BypassIp[]>([]);
const slots = ref<AgentSlot[]>([]);
const loading = ref(false);
const pingingId = ref<number | null>(null);
const checkingAll = ref(false);
const rowState = ref<Record<number, 'checking' | 'done'>>({});

const selectedIds = ref<Set<number>>(new Set());
const deletingSelected = ref(false);
const allSelected = computed(() => bypassIps.value.length > 0 && bypassIps.value.every(ip => selectedIds.value.has(ip.id)));
const toggleSelectAll = () => {
    if (allSelected.value) {
        selectedIds.value = new Set();
    } else {
        selectedIds.value = new Set(bypassIps.value.map(ip => ip.id));
    }
};
const toggleSelect = (id: number) => {
    const next = new Set(selectedIds.value);
    if (next.has(id)) { next.delete(id); } else { next.add(id); }
    selectedIds.value = next;
};
const deleteSelected = async () => {
    if (!confirm(`Delete ${selectedIds.value.size} selected IP(s)? Slots assigned to them will lose their bypass IP.`)) return;
    deletingSelected.value = true;
    try {
        await Promise.all([...selectedIds.value].map(id => axios.delete(`/api/bypass-ips/${id}`)));
        toast.success(`Deleted ${selectedIds.value.size} IP(s).`);
        selectedIds.value = new Set();
        await fetchBypassIps();
    } catch {
        toast.error('Some deletes failed.');
    } finally {
        deletingSelected.value = false;
    }
};

const markChecking = (id: number) => { rowState.value = { ...rowState.value, [id]: 'checking' }; };
const markDone = (id: number) => {
    rowState.value = { ...rowState.value, [id]: 'done' };
    setTimeout(() => {
        const next = { ...rowState.value };
        if (next[id] === 'done') {
            delete next[id];
            rowState.value = next;
        }
    }, 1500);
};

// ── Form state ──────────────────────────────────────────────────────────────
const showForm = ref(false);
const editingId = ref<number | null>(null);
const formLabel = ref('');
const formIp = ref('');
const formSaving = ref(false);
const formError = ref('');

// ── Assign state ─────────────────────────────────────────────────────────────
const assigningIpId = ref<number | null>(null);
const assigningSlotId = ref<number | null>(null);

// ── AWS subnet scan state ──────────────────────────────────────────────────────
interface ScanResultItem {
    id: number;
    ip: string;
    label: string;
    response_status: number;
    response_message: string | null;
    response_time_ms: number;
    status: 'pending' | 'approved' | 'rejected';
    created_at: string;
}

interface ScanSession {
    id: number;
    type: 'ipv4' | 'ipv6' | 'censys' | 'frontend';
    meta?: Record<string, string>;
    status: 'running' | 'stopping' | 'stopped' | 'completed' | 'paused';
    subnets: string[];
    total_candidates: number;
    probed_count: number;
    found_count: number;
    started_at: string | null;
    completed_at: string | null;
    results: ScanResultItem[];
}

const scanSession = ref<ScanSession | null>(null);
const scanStarting = ref(false);
const scanStopping = ref(false);
const scanPausing = ref(false);
const scanResuming = ref(false);
const awsScanStarting = ref(false);
const approvingId = ref<number | null>(null);
const rejectingId = ref<number | null>(null);
const approvingAll = ref(false);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const scanIsRunning = () => ['running', 'stopping', 'pausing'].includes(scanSession.value?.status ?? '');
const scanIsPaused = () => scanSession.value?.status === 'paused';
const scanIsStopped = () => scanSession.value?.status === 'stopped';

const EXPECTED_MESSAGE = "Internal server error: Request method 'GET' is not supported";
const isValidPending = (r: ScanResultItem) => {
    if (r.status !== 'pending') return false;
    if (scanSession.value?.type === 'frontend') return true;
    return (r.response_message ?? '').includes(EXPECTED_MESSAGE);
};
const visibleResults = computed(() => scanSession.value?.results?.filter(isValidPending) ?? []);
const pendingCount = computed(() => visibleResults.value.length);

const loadScanStatus = async () => {
    try {
        const { data } = await axios.get('/api/bypass-ips/scan/status');
        scanSession.value = data;
        if (data && ['running', 'stopping', 'pausing'].includes(data.status)) {
            startPolling();
        }
    } catch { /* ignore */ }
};

const startPolling = () => {
    stopPolling();
    pollTimer = setTimeout(async () => {
        try {
            const { data } = await axios.get('/api/bypass-ips/scan/status');
            scanSession.value = data;
            if (data && ['running', 'stopping', 'pausing'].includes(data.status)) {
                startPolling();
            } else {
                if (data?.status === 'completed') {
                    toast.success(`Scan complete — ${visibleResults.value.filter(r => r.status === 'pending').length} new IP(s) found.`);
                }
            }
        } catch { /* ignore network blips */ }
    }, 2000);
};

const stopPolling = () => {
    if (pollTimer !== null) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
};

const confirmScanOpen = ref(false);
const pendingScanKind = ref<'subnet' | 'aws' | 'ipv6' | 'frontend' | null>(null);
const awsIpv6ScanStarting = ref(false);

const promptStartScan = (kind: 'subnet' | 'aws' | 'ipv6' | 'frontend') => {
    pendingScanKind.value = kind;
    confirmScanOpen.value = true;
};

const runStartScan = async () => {
    const kind = pendingScanKind.value;
    confirmScanOpen.value = false;
    if (!kind) return;
    if (kind === 'subnet') {
        scanStarting.value = true;
        try {
            const { data } = await axios.post('/api/bypass-ips/scan/start');
            scanSession.value = data;
            toast.info('Scan started — probing subnets in the background.');
            startPolling();
        } catch (e: any) {
            toast.error(e?.response?.data?.error ?? 'Failed to start scan.');
        } finally {
            scanStarting.value = false;
        }
    } else if (kind === 'aws') {
        awsScanStarting.value = true;
        try {
            const { data } = await axios.post('/api/bypass-ips/scan/aws', { region: 'ap-south-1' });
            scanSession.value = data;
            toast.info('AWS Mumbai scan started — probing in background.');
            startPolling();
        } catch (e: any) {
            toast.error(e?.response?.data?.error ?? 'Failed to start AWS scan.');
        } finally {
            awsScanStarting.value = false;
        }
    } else if (kind === 'ipv6') {
        awsIpv6ScanStarting.value = true;
        try {
            const { data } = await axios.post('/api/bypass-ips/scan/aws-ipv6', { region: 'ap-south-1' });
            scanSession.value = data;
            toast.info('AWS IPv6 scan started — probing ~3.2M addresses in background.');
            startPolling();
        } catch (e: any) {
            toast.error(e?.response?.data?.error ?? 'Failed to start IPv6 scan.');
        } finally {
            awsIpv6ScanStarting.value = false;
        }
    } else {
        frontendScanStarting.value = true;
        try {
            const { data } = await axios.post('/api/bypass-ips/scan/frontend');
            scanSession.value = data;
            toast.info('Frontend origin scan started — probing ~2M IPs in background.');
            startPolling();
        } catch (e: any) {
            toast.error(e?.response?.data?.error ?? 'Failed to start frontend scan.');
        } finally {
            frontendScanStarting.value = false;
        }
    }
};

const stopScan = async () => {
    if (scanIsStopped() || scanSession.value?.status === 'completed' || scanSession.value?.status === 'stopping') {
        const id = scanSession.value?.id;
        scanSession.value = null;
        stopPolling();
        if (id) {
            try { await axios.delete(`/api/bypass-ips/scan/${id}/dismiss`); } catch { /* ignore */ }
        }
        return;
    }
    scanStopping.value = true;
    try {
        await axios.post('/api/bypass-ips/scan/stop');
        if (scanSession.value) scanSession.value.status = 'stopping';
        toast.info('Stop requested — will halt at next batch.');
    } catch {
        toast.error('Failed to stop scan.');
    } finally {
        scanStopping.value = false;
    }
};

const pauseScan = async () => {
    scanPausing.value = true;
    try {
        await axios.post('/api/bypass-ips/scan/pause');
        if (scanSession.value) scanSession.value.status = 'pausing';
        startPolling();
        toast.info('Pausing scan — will stop at next batch boundary.');
    } catch {
        toast.error('Failed to pause scan.');
    } finally {
        scanPausing.value = false;
    }
};

const resumeScan = async () => {
    scanResuming.value = true;
    try {
        const { data } = await axios.post('/api/bypass-ips/scan/resume');
        scanSession.value = data;
        startPolling();
        toast.info('Scan resumed from where it left off.');
    } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Failed to resume scan.');
    } finally {
        scanResuming.value = false;
    }
};

const approveResult = async (result: ScanResultItem) => {
    approvingId.value = result.id;
    try {
        await axios.post(`/api/bypass-ips/scan-results/${result.id}/approve`);
        result.status = 'approved';
        toast.success(`${result.ip} added to bypass IPs.`);
        await fetchBypassIps();
    } catch {
        toast.error('Approve failed.');
    } finally {
        approvingId.value = null;
    }
};

const rejectResult = async (result: ScanResultItem) => {
    rejectingId.value = result.id;
    try {
        await axios.post(`/api/bypass-ips/scan-results/${result.id}/reject`);
        result.status = 'rejected';
    } catch {
        toast.error('Reject failed.');
    } finally {
        rejectingId.value = null;
    }
};

const approveAll = async () => {
    if (!scanSession.value) return;
    approvingAll.value = true;
    try {
        const { data } = await axios.post('/api/bypass-ips/scan/approve-all', { session_id: scanSession.value.id });
        toast.success(`Approved ${data.approved} IP(s).`);
        await loadScanStatus();
        await fetchBypassIps();
    } catch {
        toast.error('Approve all failed.');
    } finally {
        approvingAll.value = false;
    }
};

const scanProgressPct = (): number => {
    if (!scanSession.value || scanSession.value.total_candidates === 0) return 0;
    return Math.min(100, Math.round((scanSession.value.probed_count / scanSession.value.total_candidates) * 100));
};

// ── Batch check state ─────────────────────────────────────────────────────────
interface BatchResult {
    ip: string;
    label: string;
    response_status: number;
    response_message: string | null;
    response_time_ms: number;
}

const showBatchPanel = ref(false);
const batchText = ref('');
const batchChecking = ref(false);
const batchResults = ref<BatchResult[]>([]);
const batchSaving = ref(false);

// ── Fetch ──────────────────────────────────────────────────────────────────

const fetchBypassIps = async () => {
    loading.value = true;
    try {
        const [ipsRes, slotsRes] = await Promise.all([
            axios.get('/api/bypass-ips-public'),
            axios.get('/api/agent-slots').catch(() => ({ data: [] })),
        ]);
        bypassIps.value = ipsRes.data;
        slots.value = slotsRes.data;
    } catch {
        toast.error('Failed to load data');
    } finally {
        loading.value = false;
    }
};

// ── CRUD ───────────────────────────────────────────────────────────────────

const openAdd = () => {
    editingId.value = null;
    formLabel.value = '';
    formIp.value = '';
    formError.value = '';
    showForm.value = true;
};

const openEdit = (ip: BypassIp) => {
    editingId.value = ip.id;
    formLabel.value = ip.label;
    formIp.value = ip.ip;
    formError.value = '';
    showForm.value = true;
};

const cancelForm = () => {
    showForm.value = false;
    editingId.value = null;
};

const saveForm = async () => {
    formError.value = '';
    if (!formLabel.value.trim() || !formIp.value.trim()) {
        formError.value = 'Label and IP are required.';
        return;
    }
    formSaving.value = true;
    try {
        if (editingId.value) {
            await axios.put(`/api/bypass-ips/${editingId.value}`, { label: formLabel.value.trim(), ip: formIp.value.trim() });
            toast.success('Bypass IP updated.');
        } else {
            await axios.post('/api/bypass-ips', { label: formLabel.value.trim(), ip: formIp.value.trim() });
            toast.success('Bypass IP added.');
        }
        showForm.value = false;
        await fetchBypassIps();
    } catch (e: any) {
        formError.value = e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {})?.[0]?.[0] ?? 'Save failed.';
    } finally {
        formSaving.value = false;
    }
};

const deleteIp = async (ip: BypassIp) => {
    if (!confirm(`Delete "${ip.label}" (${ip.ip})? Slots assigned to it will lose their bypass IP.`)) return;
    try {
        await axios.delete(`/api/bypass-ips/${ip.id}`);
        toast.success('Deleted.');
        await fetchBypassIps();
    } catch {
        toast.error('Delete failed.');
    }
};

// ── Ping ───────────────────────────────────────────────────────────────────

const ping = async (ip: BypassIp) => {
    pingingId.value = ip.id;
    markChecking(ip.id);
    try {
        const { data } = await axios.post(`/api/bypass-ips/${ip.id}/ping`);
        const entry = bypassIps.value.find(b => b.id === ip.id);
        if (entry) {
            entry.last_ping_ms = data.ping_ms;
            entry.last_pinged_at = data.last_pinged_at;
            entry.response_status = data.response_status;
            entry.response_message = data.response_message;
            entry.response_flag = data.response_flag;
            entry.response_time_ms = data.response_time_ms;
        }
        if (data.reachable) {
            toast.success(`Working — HTTP ${data.response_status}, ${data.response_time_ms}ms`);
        } else {
            toast.error(`Connection failed: ${data.response_message}`);
        }
    } catch {
        toast.error('Ping failed.');
    } finally {
        pingingId.value = null;
        markDone(ip.id);
    }
};

const pingAll = async () => {
    checkingAll.value = true;
    const ips = bypassIps.value;
    let successful = 0;
    let failed = 0;

    ips.forEach(ip => markChecking(ip.id));

    await Promise.all(ips.map(async ip => {
        try {
            const { data } = await axios.post(`/api/bypass-ips/${ip.id}/ping`);
            const entry = bypassIps.value.find(b => b.id === ip.id);
            if (entry) {
                entry.last_ping_ms = data.ping_ms;
                entry.last_pinged_at = data.last_pinged_at;
                entry.response_status = data.response_status;
                entry.response_message = data.response_message;
                entry.response_flag = data.response_flag;
                entry.response_time_ms = data.response_time_ms;
            }
            if (data.reachable) {
                successful++;
            } else {
                failed++;
            }
        } catch {
            failed++;
        } finally {
            markDone(ip.id);
        }
    }));

    toast.info(`Checked all IPs: ${successful} working, ${failed} failed`);
    checkingAll.value = false;
};

// ── Slot assignment ────────────────────────────────────────────────────────

const assignedBypassIpId = (slotId: number): number | null =>
    slots.value.find(s => s.id === slotId)?.bypass_ip_id ?? null;

const assign = async (bypassIpId: number, slotId: number | null) => {
    assigningIpId.value = bypassIpId;
    assigningSlotId.value = slotId;
    try {
        if (slotId) {
            await axios.post(`/api/bypass-ips/${bypassIpId}/assign`, { slot_id: slotId });
            toast.success('Assigned.');
        } else {
            // No slot selected — this is triggered by the unassign flow
        }
        await fetchBypassIps();
    } catch {
        toast.error('Assignment failed.');
    } finally {
        assigningIpId.value = null;
        assigningSlotId.value = null;
    }
};

const unassign = async (slotId: number) => {
    try {
        await axios.post('/api/bypass-ips/unassign', { slot_id: slotId });
        toast.success('Unassigned.');
        await fetchBypassIps();
    } catch {
        toast.error('Unassign failed.');
    }
};

const pingColor = (ms: number | null): string => {
    if (ms === null) return 'text-zinc-400';
    if (ms <= 100) return 'text-emerald-600 dark:text-emerald-400';
    if (ms <= 300) return 'text-amber-600 dark:text-amber-400';
    return 'text-red-500 dark:text-red-400';
};

const cleaningUp = ref(false);
const canRestoreCleanup = ref(false);
const restoringCleanup = ref(false);
const cleanup = async () => {
    if (!confirm('Delete all bypass IPs that are not valid IVAC origins (status not 500/503, or 500 with wrong response message)?')) return;
    cleaningUp.value = true;
    try {
        const { data } = await axios.post('/api/bypass-ips/cleanup');
        if (data.deleted > 0) {
            toast.success(`Removed ${data.deleted} invalid IP${data.deleted !== 1 ? 's' : ''}.`);
            canRestoreCleanup.value = true;
            await fetchBypassIps();
        } else {
            toast.info('Nothing to clean up — all IPs are valid.');
        }
    } catch {
        toast.error('Clean up failed.');
    } finally {
        cleaningUp.value = false;
    }
};

const restoreCleanup = async () => {
    restoringCleanup.value = true;
    try {
        const { data } = await axios.post('/api/bypass-ips/cleanup/restore');
        if (data.restored > 0) {
            toast.success(`Restored ${data.restored} IP${data.restored !== 1 ? 's' : ''}.`);
            await fetchBypassIps();
        } else {
            toast.info(data.message ?? 'Nothing to restore.');
        }
        canRestoreCleanup.value = false;
    } catch {
        toast.error('Restore failed.');
    } finally {
        restoringCleanup.value = false;
    }
};

const openBatchPanel = () => {
    batchText.value = '';
    batchResults.value = [];
    showBatchPanel.value = true;
};

const closeBatchPanel = () => {
    showBatchPanel.value = false;
    batchResults.value = [];
};

const runBatchCheck = async () => {
    if (!batchText.value.trim()) return;
    batchChecking.value = true;
    batchResults.value = [];
    try {
        const { data } = await axios.post('/api/bypass-ips/batch-check', { text: batchText.value });
        batchResults.value = data;
        if (data.length === 0) {
            toast.info('No new valid IPs found in the pasted text.');
        }
    } catch {
        toast.error('Batch check failed.');
    } finally {
        batchChecking.value = false;
    }
};

const addAllValid = async () => {
    batchSaving.value = true;
    let added = 0;
    for (const result of batchResults.value) {
        try {
            await axios.post('/api/bypass-ips', {
                label: result.label,
                ip: result.ip,
                response_status: result.response_status,
                response_message: result.response_message,
                response_time_ms: result.response_time_ms,
            });
            added++;
        } catch { /* skip */ }
    }
    toast.success(`Added ${added} bypass IP${added !== 1 ? 's' : ''}.`);
    batchSaving.value = false;
    showBatchPanel.value = false;
    batchResults.value = [];
    await fetchBypassIps();
};

// Censys origin-lookup builder. Fixed anchors: the *.ivacbd.com wildcard cert on
// port 443, hosted on Amazon (AWS). Only the scan-time freshness window is tunable,
// so the link always points at currently-live origin candidates.
const censysFreshness = ref('now-3h');

const censysFreshnessOptions = [
    { label: 'Any time', value: '' },
    { label: 'Last 1h', value: 'now-1h' },
    { label: 'Last 3h', value: 'now-3h' },
    { label: 'Last 6h', value: 'now-6h' },
    { label: 'Last 12h', value: 'now-12h' },
    { label: 'Last 24h', value: 'now-24h' },
];

const censysClauses = computed(() => {
    const clauses = ['host.services.cert.parsed.subject.common_name="*.ivacbd.com"'];
    if (censysFreshness.value) { clauses.push(`host.services.scan_time>="${censysFreshness.value}"`); }
    clauses.push('host.autonomous_system.name: "Amazon"');
    clauses.push('host.services.port="443"');
    return clauses;
});

const censysQuery = computed(() => censysClauses.value.join(' and '));

const censysQueryLines = computed(() => {
    const clauses = censysClauses.value;
    const mid = Math.ceil(clauses.length / 2);
    return [clauses.slice(0, mid).join(' and '), clauses.slice(mid).join(' and ')];
});

const censysUrl = computed(() => `https://platform.censys.io/search?q=${encodeURIComponent(censysQuery.value)}`);

// ── Frontend Origin Scan ──────────────────────────────────────────────────────
const frontendScanStarting = ref(false);

// ── Batch Frontend Probe ──────────────────────────────────────────────────────
interface FrontendProbeItem {
    ip: string;
    hit: boolean;
    status_code: number | null;
    message: string | null;
    response_time_ms: number | null;
}
const batchProbeText = ref('');
const batchProbing = ref(false);
const batchProbeResults = ref<FrontendProbeItem[]>([]);

const runBatchFrontendProbe = async () => {
    if (!batchProbeText.value.trim()) return;
    batchProbing.value = true;
    batchProbeResults.value = [];
    try {
        const { data } = await axios.post('/api/bypass-ips/batch-probe-frontend', {
            text: batchProbeText.value,
        });
        batchProbeResults.value = data.results;
        const hits = data.hit_count;
        if (hits > 0) {
            toast.success(`${hits} IP${hits !== 1 ? 's' : ''} serve the frontend bundle.`);
        } else {
            toast.info('No IPs served the frontend bundle.');
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.error ?? 'Batch probe failed.');
    } finally {
        batchProbing.value = false;
    }
};

onMounted(async () => {
    await fetchBypassIps();
    await loadScanStatus();
});

onUnmounted(stopPolling);
</script>

<template>
    <Head title="Bypass IPs" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full w-full max-w-7xl flex-1 flex-col gap-4 sm:gap-6 p-3 sm:p-6">

            <!-- Censys origin lookup — tunable query -->
            <div class="flex flex-col gap-2 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 px-4 py-2.5">
                <div class="flex items-center gap-2 text-[12px] text-zinc-700 dark:text-zinc-300">
                    <ExternalLink class="h-3.5 w-3.5 shrink-0 text-blue-500" />
                    <span class="font-medium">Censys origin lookup — <span class="font-mono">CN=*.ivacbd.com</span> · Amazon · port 443</span>
                    <a
                        :href="censysUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="ml-auto inline-flex items-center gap-1 font-medium text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors"
                    >
                        Open in Censys ↗
                    </a>
                </div>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <label class="flex items-center gap-1.5 text-[11px] text-zinc-500">
                        <span>Scanned</span>
                        <select v-model="censysFreshness" class="h-7 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 text-[11px] text-zinc-700 dark:text-zinc-300 outline-none focus:ring-1 focus:ring-blue-400 cursor-pointer">
                            <option v-for="o in censysFreshnessOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                    </label>
                </div>
                <div class="font-mono text-[10px] leading-relaxed text-zinc-500 break-all" :title="censysQuery">
                    <div>{{ censysQueryLines[0] }} and</div>
                    <div>{{ censysQueryLines[1] }}</div>
                </div>
            </div>

            <!-- Batch Frontend Probe -->
            <div class="flex flex-col gap-2 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 px-4 py-3">
                <div class="flex items-center gap-2">
                    <Crosshair class="h-4 w-4 text-emerald-500 shrink-0" />
                    <span class="text-[12px] font-semibold text-zinc-800 dark:text-zinc-200">Batch Frontend Probe</span>
                    <span class="ml-auto text-[10px] text-zinc-400 font-mono">appointment.ivacbd.com</span>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <label class="flex flex-col gap-1 flex-1 min-w-[16.25rem] text-[10px] text-zinc-500">
                        <span>Paste IPs (one per line or comma separated)</span>
                        <textarea
                            v-model="batchProbeText"
                            rows="4"
                            placeholder="13.127.201.218&#10;13.127.21.164&#10;13.234.168.150&#10;..."
                            class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 py-1.5 text-[11px] font-mono outline-none focus:ring-1 focus:ring-emerald-400 resize-y"
                        />
                    </label>
                    <div class="flex flex-col gap-2">
                        <Button size="sm" variant="outline" class="cursor-pointer self-start" :disabled="batchProbing || !batchProbeText.trim()" @click="runBatchFrontendProbe">
                            <Loader2 v-if="batchProbing" class="h-3.5 w-3.5 mr-1.5 animate-spin" />
                            <Crosshair v-else class="h-3.5 w-3.5 mr-1.5" />
                            {{ batchProbing ? 'Probing…' : 'Probe All' }}
                        </Button>
                    </div>
                </div>
                <div v-if="batchProbeResults.length" class="flex flex-col gap-0.5">
                    <div v-for="r in batchProbeResults" :key="r.ip"
                        class="flex items-center gap-3 px-2 py-1 rounded text-[11px] font-mono"
                        :class="r.hit ? 'bg-emerald-100 text-black font-semibold' : 'bg-zinc-100 text-black font-semibold'"
                    >
                        <Check v-if="r.hit" class="h-3.5 w-3.5 shrink-0 text-emerald-600" />
                        <X v-else class="h-3.5 w-3.5 shrink-0 text-zinc-400" />
                        <span class="w-32 shrink-0">{{ r.ip }}</span>
                        <span v-if="r.hit" class="text-emerald-600 dark:text-emerald-400 font-semibold">HIT</span>
                        <span v-else class="text-zinc-400">miss</span>
                        <span v-if="r.message" class="truncate text-zinc-500 dark:text-zinc-400 text-[10px]">{{ r.message }}</span>
                        <span v-if="r.response_time_ms" class="ml-auto text-[10px] text-zinc-400 shrink-0">{{ r.response_time_ms }}ms</span>
                    </div>
                </div>
            </div>

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="p-2 rounded-lg bg-orange-100 dark:bg-orange-900/20 shrink-0">
                        <Network class="h-5 w-5 text-orange-600 dark:text-orange-400" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-lg sm:text-xl font-bold tracking-tight">CF Bypass IPs</h2>
                        <p class="text-[11px] text-zinc-500 mt-0.5">Manage origin IPs that bypass Cloudflare. Assign one per worker slot — it becomes Lane 2 (L2).</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" @click="openBatchPanel" class="cursor-pointer flex-1 sm:flex-none">
                        <Filter class="h-3.5 w-3.5 mr-1.5" /> Filter Batch IPs
                    </Button>
                    <Button size="sm" variant="outline" @click="pingAll" :disabled="checkingAll || bypassIps.length === 0" class="cursor-pointer flex-1 sm:flex-none">
                        <Wifi class="h-3.5 w-3.5 mr-1.5" /> Check All
                    </Button>
                    <Button size="sm" variant="outline" @click="openAdd" class="cursor-pointer flex-1 sm:flex-none">
                        <Plus class="h-3.5 w-3.5 mr-1.5" /> Add IP
                    </Button>
                </div>
            </div>

            <!-- Scan panels — always visible, side by side -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 sm:gap-4">

                <!-- Subnet Scan panel -->
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 p-4 flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <ScanLine class="h-4 w-4 text-orange-500" />
                        <span class="text-[13px] font-semibold text-zinc-800 dark:text-zinc-200">Subnet Scan</span>
                        <span class="ml-auto text-[10px] text-zinc-400 font-mono">targeted</span>
                    </div>
                    <p class="text-[11px] text-zinc-500 leading-relaxed">
                        Finds the AWS CIDR blocks that your existing bypass IPs belong to and probes only those ranges.
                        Much faster than a full Mumbai scan — focuses on ranges already known to work.
                    </p>
                    <div class="mt-auto pt-1">
                        <Button
                            size="sm"
                            variant="outline"
                            @click="promptStartScan('subnet')"
                            :disabled="scanStarting || scanIsRunning()"
                            class="cursor-pointer"
                        >
                            <Loader2 v-if="scanStarting" class="h-3.5 w-3.5 mr-1.5 animate-spin" />
                            <ScanLine v-else class="h-3.5 w-3.5 mr-1.5" />
                            {{ scanStarting ? 'Starting…' : 'Start Subnet Scan' }}
                        </Button>
                    </div>
                </div>

                <!-- AWS Mumbai Scan panel -->
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 p-4 flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <Cloud class="h-4 w-4 text-blue-500" />
                        <span class="text-[13px] font-semibold text-zinc-800 dark:text-zinc-200">AWS Mumbai Scan</span>
                        <span class="ml-auto text-[10px] text-zinc-400 font-mono">~2M IPs · ap-south-1</span>
                    </div>
                    <p class="text-[11px] text-zinc-500 leading-relaxed">
                        Scans all AWS EC2 IP ranges in the ap-south-1 (Mumbai) region to discover new bypass IPs.
                        Prioritises CIDRs matching existing bypass IPs first. Takes 10–30 minutes.
                    </p>
                    <div class="mt-auto pt-1">
                        <Button
                            size="sm"
                            variant="outline"
                            @click="promptStartScan('aws')"
                            :disabled="awsScanStarting || scanIsRunning()"
                            class="cursor-pointer"
                        >
                            <Loader2 v-if="awsScanStarting" class="h-3.5 w-3.5 mr-1.5 animate-spin" />
                            <Cloud v-else class="h-3.5 w-3.5 mr-1.5" />
                            {{ awsScanStarting ? 'Starting…' : 'Start Full Scan' }}
                        </Button>
                    </div>
                </div>

                <!-- AWS IPv6 Scan panel -->
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 p-4 flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <Globe class="h-4 w-4 text-violet-500" />
                        <span class="text-[13px] font-semibold text-zinc-800 dark:text-zinc-200">AWS IPv6 Scan</span>
                        <span class="ml-auto text-[10px] text-zinc-400 font-mono">~3.2M addrs · ap-south-1</span>
                    </div>
                    <p class="text-[11px] text-zinc-500 leading-relaxed">
                        Probes 49 AWS IPv6 CIDR blocks in ap-south-1 — first 256 /64 subnets × 254 hosts each.
                        Finds IPv6 origins that bypass Cloudflare. Takes 60–90 minutes.
                    </p>
                    <div class="mt-auto pt-1">
                        <Button
                            size="sm"
                            variant="outline"
                            @click="promptStartScan('ipv6')"
                            :disabled="awsIpv6ScanStarting || scanIsRunning()"
                            class="cursor-pointer"
                        >
                            <Loader2 v-if="awsIpv6ScanStarting" class="h-3.5 w-3.5 mr-1.5 animate-spin" />
                            <Globe v-else class="h-3.5 w-3.5 mr-1.5" />
                            {{ awsIpv6ScanStarting ? 'Starting…' : 'Start IPv6 Scan' }}
                        </Button>
                    </div>
                </div>

                <!-- Frontend Origin Scan panel -->
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 p-4 flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <Crosshair class="h-4 w-4 text-emerald-500" />
                        <span class="text-[13px] font-semibold text-zinc-800 dark:text-zinc-200">Frontend Origin Scan</span>
                        <span class="ml-auto text-[10px] text-zinc-400 font-mono">~2M IPs · ap-south-1</span>
                    </div>
                    <p class="text-[11px] text-zinc-500 leading-relaxed">
                        Scans AWS ap-south-1 to find the origin IP behind <span class="font-mono">appointment.ivacbd.com</span>.
                        Probes each IP's challenge-free <span class="font-mono">/.well-known/</span> for a bundle reference. Same scope as the full Mumbai scan.
                    </p>
                    <div class="mt-auto pt-1">
                        <Button
                            size="sm"
                            variant="outline"
                            @click="promptStartScan('frontend')"
                            :disabled="frontendScanStarting || scanIsRunning()"
                            class="cursor-pointer"
                        >
                            <Loader2 v-if="frontendScanStarting" class="h-3.5 w-3.5 mr-1.5 animate-spin" />
                            <Crosshair v-else class="h-3.5 w-3.5 mr-1.5" />
                            {{ frontendScanStarting ? 'Starting…' : 'Start Frontend Scan' }}
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Active scan progress + results (shared between both scan types) -->
            <div v-if="scanSession && !(scanSession.status === 'stopped' && !scanSession.probed_count && !pendingCount)" class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 p-4 flex flex-col gap-3">

                <!-- Scan type badge + subnets being scanned -->
                <div class="flex items-center gap-2">
                    <span v-if="scanSession.type === 'ipv6'" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-violet-100 text-black">
                        <Globe class="h-2.5 w-2.5" /> IPv6
                    </span>
                    <span v-else-if="scanSession.type === 'frontend'" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-100 text-black">
                        <Crosshair class="h-2.5 w-2.5" /> Frontend
                    </span>
                    <span v-else class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-100 text-black">
                        IPv4
                    </span>
                </div>
                <div v-if="scanSession.subnets?.length" class="text-[11px] font-mono text-zinc-500">
                    <span v-if="scanSession.subnets.length > 8">
                        Scanning <span class="text-zinc-700 dark:text-zinc-300">{{ scanSession.subnets.length }} CIDR block{{ scanSession.subnets.length !== 1 ? 's' : '' }}</span>
                    </span>
                    <span v-else>
                        CIDRs: <span class="text-zinc-700 dark:text-zinc-300">{{ scanSession.subnets.join(', ') }}</span>
                    </span>
                </div>

                <!-- Progress bar -->
                <div class="flex flex-col gap-1.5">
                    <div class="flex items-center justify-between text-[11px] text-zinc-500">
                        <span>
                            <span v-if="scanSession.status === 'running'" class="text-blue-500 font-medium">Scanning…</span>
                            <span v-else-if="scanSession.status === 'stopping'" class="text-amber-500 font-medium">Stopping…</span>
                            <span v-else-if="scanSession.status === 'pausing'" class="text-amber-500 font-medium">Pausing…</span>
                            <span v-else-if="scanSession.status === 'paused'" class="text-amber-500 font-medium">Paused</span>
                            <span v-else-if="scanSession.status === 'completed'" class="text-emerald-600 dark:text-emerald-400 font-medium">Completed</span>
                            <span v-else class="text-zinc-400 font-medium">Stopped</span>
                            &nbsp;·&nbsp;{{ (scanSession.probed_count ?? 0).toLocaleString() }} / {{ (scanSession.total_candidates ?? 0).toLocaleString() }} probed
                            &nbsp;·&nbsp;<span class="text-emerald-600 dark:text-emerald-400">{{ pendingCount }} found</span>
                        </span>
                        <span class="font-mono font-semibold">{{ scanProgressPct() }}%</span>
                    </div>
                    <div class="w-full h-1.5 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                        <div
                            class="h-full rounded-full transition-all duration-500"
                            :class="scanSession.status === 'completed' ? 'bg-emerald-500' : ['stopping', 'pausing', 'paused'].includes(scanSession.status) ? 'bg-amber-400' : 'bg-blue-500'"
                            :style="{ width: scanProgressPct() + '%' }"
                        />
                    </div>
                </div>

                <!-- Controls -->
                <div class="flex items-center gap-2">
                    <Button
                        v-if="scanSession?.status === 'running' || scanSession?.status === 'pausing'"
                        size="sm"
                        variant="outline"
                        @click="pauseScan"
                        :disabled="scanPausing || scanSession?.status === 'pausing'"
                        class="cursor-pointer"
                    >
                        <Loader2 v-if="scanPausing || scanSession?.status === 'pausing'" class="h-3.5 w-3.5 mr-1.5 animate-spin" />
                        <Pause v-else class="h-3.5 w-3.5 mr-1.5" />
                        {{ (scanPausing || scanSession?.status === 'pausing') ? 'Pausing…' : 'Pause' }}
                    </Button>
                    <Button
                        v-if="scanIsPaused() || scanIsStopped()"
                        size="sm"
                        variant="outline"
                        @click="resumeScan"
                        :disabled="scanResuming"
                        class="cursor-pointer"
                    >
                        <Loader2 v-if="scanResuming" class="h-3.5 w-3.5 mr-1.5 animate-spin" />
                        <Play v-else class="h-3.5 w-3.5 mr-1.5" />
                        {{ scanResuming ? 'Resuming…' : (scanIsStopped() ? `Resume from ${scanProgressPct()}%` : 'Resume') }}
                    </Button>
                    <Button
                        v-if="scanIsRunning() || scanIsPaused() || scanIsStopped() || scanSession?.status === 'completed'"
                        size="sm"
                        variant="outline"
                        @click="stopScan"
                        :disabled="scanStopping"
                        class="cursor-pointer"
                    >
                        <X class="h-3.5 w-3.5 mr-1.5" />
                        {{ scanSession?.status === 'stopping' ? 'Force Stop' : (scanIsStopped() || scanSession?.status === 'completed') ? 'Dismiss' : 'Stop' }}
                    </Button>
                    <Button
                        v-if="pendingCount"
                        size="sm"
                        variant="outline"
                        @click="approveAll"
                        :disabled="approvingAll"
                        class="cursor-pointer"
                    >
                        <Loader2 v-if="approvingAll" class="h-3.5 w-3.5 mr-1.5 animate-spin" />
                        <Check v-else class="h-3.5 w-3.5 mr-1.5" />
                        {{ approvingAll ? 'Approving…' : `Approve All Pending (${pendingCount})` }}
                    </Button>
                </div>

                <!-- Results table -->
                <div v-if="visibleResults.length" class="flex flex-col gap-1.5">
                    <p class="text-[11px] font-semibold text-zinc-600 dark:text-zinc-400">
                        Discovered IPs
                        <span v-if="pendingCount" class="text-amber-600 dark:text-amber-400">
                            — {{ pendingCount }} awaiting review
                        </span>
                        <span v-else class="text-emerald-600 dark:text-emerald-400">— all reviewed</span>
                    </p>
                    <div class="rounded-md border border-zinc-200 dark:border-zinc-700 overflow-x-auto">
                        <table class="w-full text-[12px] min-w-[40rem]">
                            <thead class="bg-zinc-100 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                                <tr>
                                    <th class="px-3 py-1.5 text-left text-[10px] uppercase tracking-wider font-semibold text-zinc-400">IP</th>
                                    <th class="px-3 py-1.5 text-left text-[10px] uppercase tracking-wider font-semibold text-zinc-400">Code</th>
                                    <th class="px-3 py-1.5 text-left text-[10px] uppercase tracking-wider font-semibold text-zinc-400">Message</th>
                                    <th class="px-3 py-1.5 text-left text-[10px] uppercase tracking-wider font-semibold text-zinc-400">Time</th>
                                    <th class="px-3 py-1.5 text-left text-[10px] uppercase tracking-wider font-semibold text-zinc-400">State</th>
                                    <th class="px-3 py-1.5 text-center text-[10px] uppercase tracking-wider font-semibold text-zinc-400">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-for="r in visibleResults" :key="r.id"
                                    :class="r.status === 'rejected' ? 'opacity-40' : ''">
                                    <td class="px-3 py-1.5 font-mono text-orange-600 dark:text-orange-400 font-semibold whitespace-nowrap">{{ r.ip }}</td>
                                    <td class="px-3 py-1.5 whitespace-nowrap">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono font-semibold"
                                            :class="r.response_status === 500 ? 'bg-emerald-100 text-black' : 'bg-zinc-100 text-black'">
                                            {{ r.response_status ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-1.5 text-zinc-500 dark:text-zinc-400 max-w-xs truncate whitespace-nowrap" :title="r.response_message ?? ''">
                                        {{ r.response_message ?? '—' }}
                                    </td>
                                    <td class="px-3 py-1.5 font-mono text-zinc-500 whitespace-nowrap">{{ r.response_time_ms }}ms</td>
                                    <td class="px-3 py-1.5 whitespace-nowrap">
                                        <span v-if="r.status === 'approved'" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-100 text-black">
                                            <Check class="h-2.5 w-2.5" /> Approved
                                        </span>
                                        <span v-else-if="r.status === 'rejected'" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-zinc-100 text-black">
                                            <X class="h-2.5 w-2.5" /> Rejected
                                        </span>
                                        <span v-else class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-black">
                                            Pending
                                        </span>
                                    </td>
                                    <td class="px-3 py-1.5 text-center whitespace-nowrap">
                                        <div v-if="r.status === 'pending'" class="flex items-center justify-center gap-1">
                                            <Button size="icon" variant="ghost" class="h-6 w-6 cursor-pointer"
                                                :disabled="approvingId === r.id"
                                                @click="approveResult(r)" title="Add to bypass IPs">
                                                <Loader2 v-if="approvingId === r.id" class="h-3 w-3 animate-spin" />
                                                <Check v-else class="h-3 w-3" />
                                            </Button>
                                            <Button size="icon" variant="ghost" class="h-6 w-6 cursor-pointer"
                                                :disabled="rejectingId === r.id"
                                                @click="rejectResult(r)" title="Dismiss">
                                                <Loader2 v-if="rejectingId === r.id" class="h-3 w-3 animate-spin" />
                                                <X v-else class="h-3 w-3" />
                                            </Button>
                                        </div>
                                        <span v-else class="text-zinc-300 dark:text-zinc-700 text-[11px]">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div v-else-if="!scanIsRunning() && !scanSession.results?.length" class="text-[11px] text-zinc-400 italic">
                    No new IPs found in the last scan.
                </div>
            </div>

            <!-- Batch Panel -->
            <div v-if="showBatchPanel" class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 p-4 flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <p class="text-[12px] font-semibold text-zinc-700 dark:text-zinc-300 flex items-center gap-1.5">
                        <Filter class="h-3.5 w-3.5 text-zinc-400" /> Filter Batch IPs
                    </p>
                    <button @click="closeBatchPanel" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <p class="text-[11px] text-zinc-500">Paste raw Censys host results below. IPs will be extracted, tested against <span class="font-mono">api.ivacbd.com</span>, and only those returning HTTP 500 (valid origins) will be listed.</p>
                <textarea
                    v-model="batchText"
                    placeholder="Paste Censys search results here..."
                    rows="8"
                    class="w-full rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-3 py-2 text-[12px] font-mono outline-none focus:ring-1 focus:ring-orange-400 resize-y"
                />
                <div class="flex items-center gap-2">
                    <Button size="sm" variant="outline" @click="runBatchCheck" :disabled="batchChecking || !batchText.trim()" class="cursor-pointer">
                        <Loader2 v-if="batchChecking" class="h-3.5 w-3.5 mr-1.5 animate-spin" />
                        <Filter v-else class="h-3.5 w-3.5 mr-1.5" />
                        {{ batchChecking ? 'Testing IPs…' : 'Extract & Test' }}
                    </Button>
                    <span v-if="batchChecking" class="text-[11px] text-zinc-400">This may take up to 30s depending on IP count…</span>
                </div>

                <!-- Batch results -->
                <div v-if="batchResults.length > 0" class="flex flex-col gap-2">
                    <p class="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">{{ batchResults.length }} valid IP{{ batchResults.length !== 1 ? 's' : '' }} found:</p>
                    <div class="rounded-md border border-zinc-200 dark:border-zinc-700 overflow-x-auto">
                        <table class="w-full text-[12px] min-w-[40rem]">
                            <thead class="bg-zinc-100 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                                <tr>
                                    <th class="px-3 py-1.5 text-left text-[10px] uppercase tracking-wider font-semibold text-zinc-400">IP</th>
                                    <th class="px-3 py-1.5 text-left text-[10px] uppercase tracking-wider font-semibold text-zinc-400">Label</th>
                                    <th class="px-3 py-1.5 text-left text-[10px] uppercase tracking-wider font-semibold text-zinc-400">Status</th>
                                    <th class="px-3 py-1.5 text-left text-[10px] uppercase tracking-wider font-semibold text-zinc-400">Response</th>
                                    <th class="px-3 py-1.5 text-left text-[10px] uppercase tracking-wider font-semibold text-zinc-400">Time</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-for="r in batchResults" :key="r.ip">
                                    <td class="px-3 py-1.5 font-mono text-orange-600 dark:text-orange-400 font-semibold whitespace-nowrap">{{ r.ip }}</td>
                                    <td class="px-3 py-1.5 text-zinc-700 dark:text-zinc-300 whitespace-nowrap">{{ r.label }}</td>
                                    <td class="px-3 py-1.5 whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-100 text-black">
                                            <Check class="h-2.5 w-2.5" />{{ r.response_status }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-1.5 text-zinc-500 dark:text-zinc-400 max-w-xs truncate whitespace-nowrap" :title="r.response_message ?? ''">{{ r.response_message ?? '—' }}</td>
                                    <td class="px-3 py-1.5 font-mono text-zinc-500 whitespace-nowrap">{{ r.response_time_ms }}ms</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button size="sm" variant="outline" @click="addAllValid" :disabled="batchSaving" class="cursor-pointer">
                            <Loader2 v-if="batchSaving" class="h-3.5 w-3.5 mr-1.5 animate-spin" />
                            <Check v-else class="h-3.5 w-3.5 mr-1.5" />
                            {{ batchSaving ? 'Saving…' : `Add All ${batchResults.length} IPs` }}
                        </Button>
                        <Button size="sm" variant="ghost" @click="batchResults = []" class="cursor-pointer text-zinc-400">Clear results</Button>
                    </div>
                </div>
            </div>

            <!-- Add / Edit form -->
            <div v-if="showForm" class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 p-4 flex flex-col gap-3">
                <p class="text-[12px] font-semibold text-zinc-700 dark:text-zinc-300">{{ editingId ? 'Edit Bypass IP' : 'New Bypass IP' }}</p>
                <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                    <input
                        v-model="formLabel"
                        placeholder="Label (e.g. AWS Mumbai 1)"
                        class="flex-1 h-8 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-3 text-[12px] outline-none focus:ring-1 focus:ring-orange-400"
                    />
                    <input
                        v-model="formIp"
                        placeholder="IP address (e.g. 65.1.89.220)"
                        class="w-full sm:w-52 h-8 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-3 text-[12px] font-mono outline-none focus:ring-1 focus:ring-orange-400"
                    />
                    <div class="flex gap-2">
                        <Button size="sm" variant="outline" :disabled="formSaving" @click="saveForm" class="cursor-pointer flex-1 sm:flex-none">
                            <Check class="h-3.5 w-3.5 mr-1" /> {{ editingId ? 'Update' : 'Save' }}
                        </Button>
                        <Button size="sm" variant="ghost" @click="cancelForm" class="cursor-pointer">
                            <X class="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </div>
                <p v-if="formError" class="text-[11px] text-red-500">{{ formError }}</p>
            </div>

            <!-- Table -->
            <div class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b border-zinc-200/60 dark:border-zinc-700/60">
                    <span class="text-[11px] font-semibold uppercase tracking-widest text-zinc-400">Configured Bypass IPs</span>
                    <div class="flex items-center gap-3">
                        <span class="text-[11px] text-zinc-400 tabular-nums">{{ bypassIps.length }} IP{{ bypassIps.length !== 1 ? 's' : '' }}</span>
                        <Button
                            v-if="canRestoreCleanup"
                            size="sm"
                            variant="outline"
                            class="cursor-pointer h-7 text-[11px]"
                            :disabled="restoringCleanup"
                            @click="restoreCleanup"
                        >
                            <Loader2 v-if="restoringCleanup" class="h-3 w-3 mr-1.5 animate-spin" />
                            <Undo2 v-else class="h-3 w-3 mr-1.5" />
                            {{ restoringCleanup ? 'Restoring…' : 'Restore' }}
                        </Button>
                        <Button
                            v-if="bypassIps.length > 0"
                            size="sm"
                            variant="outline"
                            class="cursor-pointer h-7 text-[11px]"
                            :disabled="cleaningUp"
                            @click="cleanup"
                        >
                            <Loader2 v-if="cleaningUp" class="h-3 w-3 mr-1.5 animate-spin" />
                            <Eraser v-else class="h-3 w-3 mr-1.5" />
                            {{ cleaningUp ? 'Cleaning…' : 'Clean Up' }}
                        </Button>
                    </div>
                </div>
                <div v-if="loading" class="p-10 text-center text-sm text-zinc-400">Loading…</div>
                <div v-else-if="bypassIps.length === 0" class="p-10 text-center text-sm text-zinc-400 border border-dashed border-zinc-200 dark:border-zinc-800 rounded-lg">
                    No bypass IPs configured yet. Add one to assign it to a worker.
                </div>
                <!-- Delete selected toolbar -->
                <div v-if="selectedIds.size > 0" class="flex items-center gap-3 px-3 py-2 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800/40 rounded-lg mb-2">
                    <span class="text-[12px] text-red-700 dark:text-red-400 font-medium">{{ selectedIds.size }} selected</span>
                    <Button size="sm" variant="outline" class="cursor-pointer h-7 text-[11px] ml-auto" :disabled="deletingSelected" @click="deleteSelected">
                        <Loader2 v-if="deletingSelected" class="h-3 w-3 mr-1.5 animate-spin" />
                        <Trash2 v-else class="h-3 w-3 mr-1.5" />
                        {{ deletingSelected ? 'Deleting…' : 'Delete selected' }}
                    </Button>
                    <Button size="sm" variant="ghost" class="cursor-pointer h-7 text-[11px] text-zinc-500" @click="selectedIds = new Set()">
                        Cancel
                    </Button>
                </div>

                <div v-if="bypassIps.length > 0" class="overflow-x-auto">
                <Table class="border-b min-w-[45rem]">
                    <TableHeader class="bg-zinc-50/60 dark:bg-zinc-900/40 border-b border-zinc-200/60 dark:border-zinc-700/60">
                        <TableRow>
                            <TableHead class="pl-3 pr-2 py-2 w-8 border-r border-zinc-200/60 dark:border-zinc-700/60">
                                <input type="checkbox" :checked="allSelected" @change="toggleSelectAll" class="rounded border-zinc-300 dark:border-zinc-600 cursor-pointer accent-orange-500" />
                            </TableHead>
                            <TableHead class="pl-3 pr-2 py-2 text-center text-[10px] uppercase tracking-widest font-semibold text-zinc-400 w-12 border-r border-zinc-200/60 dark:border-zinc-700/60">S/N</TableHead>
                            <TableHead class="px-2 py-2 text-[10px] uppercase tracking-widest font-semibold text-zinc-400">Label</TableHead>
                            <TableHead class="px-2 py-2 text-[10px] uppercase tracking-widest font-semibold text-zinc-400 w-40 whitespace-nowrap">IP Address</TableHead>
                            <TableHead class="px-2 py-2 text-[10px] uppercase tracking-widest font-semibold text-zinc-400 text-center whitespace-nowrap">Status</TableHead>
                            <TableHead class="px-2 py-2 text-[10px] uppercase tracking-widest font-semibold text-zinc-400 whitespace-nowrap">Response</TableHead>
                            <TableHead class="px-2 py-2 text-[10px] uppercase tracking-widest font-semibold text-zinc-400 whitespace-nowrap">Last Check</TableHead>
                            <TableHead class="pl-2 pr-3 py-2 text-center text-[10px] uppercase tracking-widest font-semibold text-zinc-400 w-20 border-l border-zinc-200/60 dark:border-zinc-700/60">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="(ip, idx) in bypassIps" :key="ip.id" class="hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40 transition-colors" :class="selectedIds.has(ip.id) ? 'bg-orange-50/60 dark:bg-orange-950/10' : ''">
                            <!-- Checkbox -->
                            <TableCell class="pl-3 pr-2 py-1.5 border-r border-zinc-200/60 dark:border-zinc-700/60 w-8">
                                <input type="checkbox" :checked="selectedIds.has(ip.id)" @change="toggleSelect(ip.id)" class="rounded border-zinc-300 dark:border-zinc-600 cursor-pointer accent-orange-500" />
                            </TableCell>
                            <!-- S/N -->
                            <TableCell class="pl-3 pr-2 py-1.5 text-center text-[10px] text-zinc-400 dark:text-zinc-600 font-mono tabular-nums border-r border-zinc-200/60 dark:border-zinc-700/60">{{ idx + 1 }}</TableCell>
                            <!-- Label -->
                            <TableCell class="px-2 py-1.5 whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    <div class="p-1.5 rounded bg-orange-100 dark:bg-orange-900/20">
                                        <Network class="h-3.5 w-3.5 text-orange-600 dark:text-orange-400" />
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-[11px] font-semibold">{{ ip.label }}</span>
                                        <span class="text-[10px] text-zinc-400">ID: {{ ip.id }}</span>
                                    </div>
                                </div>
                            </TableCell>
                            <!-- IP -->
                            <TableCell class="px-2 py-1.5 font-mono text-[12px] text-orange-600 dark:text-orange-400 font-semibold whitespace-nowrap">{{ ip.ip }}</TableCell>
                            <!-- Status -->
                            <TableCell class="px-2 py-1.5 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <span v-if="rowState[ip.id] === 'checking'" class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-[11px] font-semibold bg-blue-100 text-black whitespace-nowrap">
                                        <Loader2 class="h-3 w-3 shrink-0 animate-spin" />
                                        Checking…
                                    </span>
                                    <span v-else-if="rowState[ip.id] === 'done'" class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md text-[11px] font-semibold bg-emerald-100 text-black whitespace-nowrap animate-in fade-in zoom-in duration-200">
                                        <Check class="h-3 w-3 shrink-0" />
                                        Done
                                    </span>
                                    <div v-else-if="ip.response_time_ms !== null" class="flex items-center gap-1.5">
                                        <span v-if="ip.response_status === 500 || ip.response_status === 503 || (ip.response_status && ip.response_status < 400)" class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap bg-emerald-100 text-black"
                                            title="Reached the IVAC origin — bypass is working">
                                            <Check class="h-3 w-3 shrink-0" />
                                            {{ ip.response_status }}
                                        </span>
                                        <span v-else-if="ip.response_status && ip.response_status < 500" class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap bg-orange-100 text-black"
                                            title="Reached the origin (client error)">
                                            <Check class="h-3 w-3 shrink-0" />
                                            {{ ip.response_status }}
                                        </span>
                                        <span v-else class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold bg-red-100 text-black whitespace-nowrap">
                                            <X class="h-3 w-3 shrink-0" />
                                            {{ ip.response_status ?? 'fail' }}
                                        </span>
                                        <span class="font-mono text-[11px] text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ ip.response_time_ms }}ms</span>
                                    </div>
                                    <span v-else class="text-zinc-400 text-[11px]">—</span>
                                    <Button size="icon" variant="ghost" class="h-6 w-6 cursor-pointer shrink-0" :disabled="pingingId === ip.id || rowState[ip.id] === 'checking'"
                                        @click="ping(ip)" :title="`Test ${ip.ip} API`">
                                        <Loader2 v-if="rowState[ip.id] === 'checking'" class="h-3 w-3 text-blue-500 animate-spin" />
                                        <Wifi v-else class="h-3 w-3 text-zinc-400" />
                                    </Button>
                                </div>
                            </TableCell>
                            <!-- Response Message -->
                            <TableCell class="px-2 py-1.5">
                                <span v-if="ip.response_message" class="text-[11px] text-zinc-600 dark:text-zinc-400 max-w-xs truncate whitespace-nowrap" :title="ip.response_message">
                                    {{ ip.response_message }}
                                </span>
                                <span v-else class="text-zinc-400 text-[11px] whitespace-nowrap">—</span>
                            </TableCell>
                            <!-- Last Check Time -->
                            <TableCell class="px-2 py-1.5 whitespace-nowrap">
                                <span v-if="ip.last_pinged_at" class="text-[11px] text-zinc-600 dark:text-zinc-400" :title="ip.last_pinged_at">
                                    {{ new Date(ip.last_pinged_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }) }}
                                </span>
                                <span v-else class="text-zinc-400 text-[11px]">—</span>
                            </TableCell>
                            <!-- Actions -->
                            <TableCell class="pl-2 pr-3 py-1.5 text-center whitespace-nowrap border-l border-zinc-200/60 dark:border-zinc-700/60">
                                <div class="flex items-center justify-center gap-1">
                                    <Button size="icon" variant="ghost" class="h-6 w-6 text-zinc-400 hover:text-zinc-700 cursor-pointer" @click="openEdit(ip)">
                                        <Pencil class="h-3 w-3" />
                                    </Button>
                                    <Button size="icon" variant="ghost" class="h-6 w-6 cursor-pointer" @click="deleteIp(ip)">
                                        <Trash2 class="h-3 w-3" />
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                </div>
            </div>

            <!-- Ubuntu: emergency stop commands -->
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 p-4 flex flex-col gap-3">
                <div class="flex items-center gap-2">
                    <Terminal class="h-4 w-4 text-zinc-400" />
                    <span class="text-[12px] font-semibold text-zinc-600 dark:text-zinc-400">Stop Scan from Ubuntu Server</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="flex flex-col gap-1">
                        <span class="text-[10px] uppercase tracking-wider font-semibold text-zinc-400">Graceful stop</span>
                        <code class="text-[11px] font-mono bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 px-2.5 py-1.5 rounded border border-zinc-200 dark:border-zinc-700 select-all">sudo systemctl stop ipms-scan-worker</code>
                        <span class="text-[10px] text-zinc-400">Halts at the next batch boundary (up to ~1s delay)</span>
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[10px] uppercase tracking-wider font-semibold text-zinc-400">Force kill</span>
                        <code class="text-[11px] font-mono bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 px-2.5 py-1.5 rounded border border-zinc-200 dark:border-zinc-700 select-all">sudo pkill -9 -f "queue:work.*scan"</code>
                        <span class="text-[10px] text-zinc-400">Kills the worker immediately — then click Stop in UI to mark session stopped</span>
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-[10px] uppercase tracking-wider font-semibold text-zinc-400">Check status</span>
                        <code class="text-[11px] font-mono bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 px-2.5 py-1.5 rounded border border-zinc-200 dark:border-zinc-700 select-all">sudo systemctl status ipms-scan-worker</code>
                        <span class="text-[10px] text-zinc-400">Shows whether the worker process is active and its last log lines</span>
                    </div>
                </div>
            </div>

        </div>

        <!-- Start scan confirmation modal -->
        <Dialog v-model:open="confirmScanOpen">
            <DialogContent class="sm:max-w-[28.75rem] p-0 overflow-hidden !bg-white dark:!bg-zinc-900">
                <DialogHeader class="px-5 pt-5 pb-3 border-b border-zinc-200 dark:border-zinc-800">
                    <div class="flex items-center gap-2.5">
                        <div
                            :class="pendingScanKind === 'aws'
                                ? 'h-9 w-9 rounded-full bg-amber-100 dark:bg-amber-950/40 flex items-center justify-center'
                                : pendingScanKind === 'frontend'
                                    ? 'h-9 w-9 rounded-full bg-emerald-100 dark:bg-emerald-950/40 flex items-center justify-center'
                                    : 'h-9 w-9 rounded-full bg-orange-100 dark:bg-orange-950/40 flex items-center justify-center'"
                        >
                            <Crosshair v-if="pendingScanKind === 'frontend'" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                            <AlertTriangle v-else-if="pendingScanKind === 'aws'" class="h-4 w-4 text-amber-600 dark:text-amber-400" />
                            <ScanLine v-else class="h-4 w-4 text-orange-600 dark:text-orange-400" />
                        </div>
                        <div class="flex flex-col">
                            <DialogTitle class="text-[14px] font-semibold">
                                {{ pendingScanKind === 'aws' ? 'Start full AWS Mumbai scan?' : pendingScanKind === 'frontend' ? 'Start frontend origin scan?' : 'Start subnet scan?' }}
                            </DialogTitle>
                            <DialogDescription class="text-[11px] text-zinc-500 mt-0.5">
                                {{ pendingScanKind === 'aws' ? 'This is a long-running operation.' : pendingScanKind === 'frontend' ? 'Scans ap-south-1 for appointment.ivacbd.com origin.' : 'Probes only the CIDR blocks of existing bypass IPs.' }}
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>
                <div class="px-5 py-4 space-y-3">
                    <div v-if="pendingScanKind === 'aws'" class="rounded-md border border-amber-200 dark:border-amber-900/40 bg-amber-50 dark:bg-amber-950/20 p-3 text-[12px] text-amber-800 dark:text-amber-300 leading-relaxed">
                        Scans <span class="font-mono font-semibold">~2,060,000</span> IPs across all EC2 ranges in
                        <span class="font-mono font-semibold">ap-south-1</span>.
                        Takes <span class="font-semibold">10–30 minutes</span>.
                    </div>
                    <div v-else-if="pendingScanKind === 'frontend'" class="rounded-md border border-emerald-200 dark:border-emerald-900/40 bg-emerald-50 dark:bg-emerald-950/20 p-3 text-[12px] text-emerald-800 dark:text-emerald-300 leading-relaxed">
                        Probes <span class="font-mono font-semibold">~2,060,000</span> IPs in <span class="font-mono font-semibold">ap-south-1</span> for
                        <span class="font-mono font-semibold">appointment.ivacbd.com</span> via a challenge-free <span class="font-mono">/.well-known/</span> request.
                        Takes <span class="font-semibold">10–30 minutes</span>.
                    </div>
                    <div v-else class="rounded-md border border-orange-200 dark:border-orange-900/40 bg-orange-50 dark:bg-orange-950/20 p-3 text-[12px] text-orange-800 dark:text-orange-300 leading-relaxed">
                        Finds AWS CIDR blocks containing your current bypass IPs and probes those ranges only.
                        Usually completes within a few minutes.
                    </div>
                    <div class="rounded-md border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/40 p-3 text-[11px] text-zinc-600 dark:text-zinc-400 leading-relaxed flex items-start gap-2">
                        <AlertTriangle class="h-3.5 w-3.5 mt-0.5 shrink-0 text-zinc-400" />
                        <span>Any scan currently running will be stopped before this one begins.</span>
                    </div>
                </div>
                <DialogFooter class="px-5 py-3 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-900/40 flex gap-2">
                    <Button variant="outline" size="sm" class="cursor-pointer" @click="confirmScanOpen = false">Cancel</Button>
                    <Button size="sm" variant="outline" class="cursor-pointer" @click="runStartScan">
                        <Crosshair v-if="pendingScanKind === 'frontend'" class="h-3.5 w-3.5 mr-1.5" />
                        <ScanLine v-else-if="pendingScanKind === 'subnet'" class="h-3.5 w-3.5 mr-1.5" />
                        <Cloud v-else class="h-3.5 w-3.5 mr-1.5" />
                        {{ pendingScanKind === 'aws' ? 'Start Full Scan' : pendingScanKind === 'frontend' ? 'Start Frontend Scan' : 'Start Subnet Scan' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
