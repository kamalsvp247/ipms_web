<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { RefreshCw, Trash2, Timer, ArrowUp, ArrowDown, ArrowUpDown, BarChart2, LogIn, ShieldCheck, ShieldAlert, CalendarCheck, CreditCard, Bell, Activity, FileDown, type LucideComponent } from 'lucide-vue-next';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useToast } from 'vue-toastification';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import { type BreadcrumbItem } from '@/types';

const toast = useToast();
const page = usePage();
const isSuperAdmin = computed(() => (page.props.auth?.user as { role?: string } | undefined)?.role === 'super_admin');

interface AgentSlotOption {
    id: number;
    name: string;
}

interface BotLog {
    id: number;
    agent_slot_id: number | null;
    account_phone: string | null;
    label: string | null;
    method: string;
    url: string;
    status_code: number | null;
    duration_ms: number | null;
    protocol_version: string | null;
    remote_ip: string | null;
    request_body: string | null;
    response_body: string | null;
    error_type: string | null;
    error_message: string | null;
    logged_at: string;
    jwt_expires_at: string | null;
    agent_slot?: { id: number; name: string } | null;
}

interface SummaryRow {
    status_code: number | null;
    count: number;
    avg_duration_ms: number | null;
}

interface EndpointRow {
    url: string;
    path: string;
    count: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Log Analysis', href: '/log-analysis' },
];

const slots = ref<AgentSlotOption[]>([]);
const phones = ref<string[]>([]);
const otpCounts = ref<Record<string, number>>({});
const entries = ref<BotLog[]>([]);
const summary = ref<SummaryRow[]>([]);
const endpoints = ref<EndpointRow[]>([]);
const loadingEntries = ref(false);
const deletingId = ref<number | null>(null);
const deletingAll = ref(false);
const filterStatusCode = ref('');
const filterEndpoints = ref<string[]>([]);
const filterSlot = ref('');
const filterPhones = ref<string[]>([]);
const filterMethod = ref<string>('POST');
const filterHasReservationId = ref(false);
const filterProxyOnly = ref(false);
const sortBy = ref<'logged_at' | 'received_at' | 'duration_ms'>('logged_at');
const sortDir = ref<'asc' | 'desc'>('desc');
const expandedRow = ref<number | null>(null);
const currentPage = ref(1);
const perPage = ref(50);
const totalEntries = ref(0);
const lastPage = ref(1);
let filterDebounce: ReturnType<typeof setTimeout> | null = null;
/** This page is watched live while a race is running, so it refreshes on its own from the start. */
const autoRefresh = ref(true);
const autoRefreshInterval = ref(5);
let autoRefreshTimer: ReturnType<typeof setInterval> | null = null;
const backgroundRefreshing = ref(false);

function startAutoRefresh() {
    if (autoRefreshTimer) clearInterval(autoRefreshTimer);
    autoRefreshTimer = setInterval(() => reloadAll(false, true), autoRefreshInterval.value * 1000);
}

function toggleAutoRefresh() {
    autoRefresh.value = !autoRefresh.value;
    if (autoRefresh.value) {
        startAutoRefresh();
    } else {
        if (autoRefreshTimer) clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }
}

function statusBadgeClass(code: number | null | undefined, error?: string | null): string {
    if (error) {
        if (error.includes('SocketTimeout')) return 'bg-orange-100 text-orange-800 dark:bg-orange-950/40 dark:text-orange-300';
        return 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300';
    }
    if (!code) return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-900 dark:text-zinc-400';
    if (code === 429) return 'bg-orange-100 text-orange-800 dark:bg-orange-950/40 dark:text-orange-300';
    if (code === 403) return 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300';
    if (code === 401) return 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300';
    if (code >= 500) return 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300';
    if (code >= 400) return 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300';
    if (code >= 200 && code < 300) return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300';
    return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-900 dark:text-zinc-400';
}

function statusBarColor(code: number | null): string {
    if (!code) return 'bg-zinc-400';
    if (code === 429) return 'bg-orange-500';
    if (code >= 500) return 'bg-red-600';
    if (code >= 400) return 'bg-red-500';
    if (code >= 200 && code < 300) return 'bg-emerald-500';
    return 'bg-zinc-400';
}

function statusTextColor(code: number | null): string {
    if (!code) return 'text-zinc-500';
    if (code === 429) return 'text-orange-500';
    if (code >= 500) return 'text-red-600';
    if (code >= 400) return 'text-red-500';
    if (code >= 200 && code < 300) return 'text-emerald-500';
    return 'text-zinc-500';
}

function durationClass(ms: number | null): string {
    if (!ms) return 'text-zinc-900 dark:text-zinc-100';
    if (ms < 300) return 'text-emerald-700 dark:text-emerald-400';
    if (ms < 1000) return 'text-orange-600 dark:text-orange-400';
    return 'text-red-600 dark:text-red-400';
}

function durationBarClass(ms: number | null): string {
    if (!ms) return 'bg-zinc-300 dark:bg-zinc-600';
    if (ms < 300) return 'bg-emerald-500 dark:bg-emerald-500';
    if (ms < 1000) return 'bg-orange-500 dark:bg-orange-500';
    return 'bg-red-500 dark:bg-red-500';
}

function formatDuration(ms: number | null): string {
    if (ms == null) return '—';
    if (ms > 999) return `${(ms / 1000).toFixed(2)}s`;
    return `${ms}ms`;
}

function fmtJwtExpiresIn(iso: string | null): string {
    if (!iso) return '—';
    const secs = Math.floor((new Date(iso).getTime() - Date.now()) / 1000);
    if (secs <= 0) return 'expired';
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return m > 0 ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`;
}

function methodClass(method: string): string {
    if (method === 'GET') return 'text-blue-700 dark:text-blue-400';
    if (method === 'POST') return 'text-emerald-700 dark:text-emerald-400';
    if (method === 'PUT' || method === 'PATCH') return 'text-orange-600 dark:text-orange-400';
    if (method === 'DELETE') return 'text-red-600 dark:text-red-400';
    return 'text-zinc-600 dark:text-zinc-400';
}

function shortEndpoint(url: string): string {
    try {
        const parsed = new URL(url);
        return parsed.host + parsed.pathname;
    } catch {
        return url;
    }
}

function endpointAction(url: string): string {
    try {
        return new URL(url).pathname.split('/').filter(Boolean).pop()?.replace(/\.json$/, '') ?? '';
    } catch {
        return url.split('/').pop()?.replace(/\.json$/, '') ?? url;
    }
}

function endpointIcon(url: string): LucideComponent {
    const action = endpointAction(url).toLowerCase();
    if (action.includes('signin') || action.includes('sign-in')) return LogIn;
    if (action.includes('otp') || action.includes('verify')) return ShieldCheck;
    if (action.includes('reserve') || action.includes('slot')) return CalendarCheck;
    if (action.includes('initiate') || action.includes('payment')) return CreditCard;
    if (action.includes('invoice') || action.includes('download')) return FileDown;
    if (url.includes('firebase') || url.includes('/otps/')) return Bell;
    return Activity;
}

function endpointPathPrefix(url: string): string {
    try {
        const parts = new URL(url).pathname.split('/').filter(Boolean);
        parts.pop();
        return parts.length ? '/' + parts.join('/') + '/' : '/';
    } catch {
        const idx = url.lastIndexOf('/');
        return idx >= 0 ? url.slice(0, idx + 1) : '';
    }
}

function resolvedIp(entry: BotLog): string {
    if (entry.remote_ip) return entry.remote_ip;
    if (entry.url?.includes('/otps/')) return 'firebase.com';
    return 'api.ivacbd.com';
}

// The edge-throttle fallback client (Oxylabs) is tagged "proxy:<host>" by IvacHttpClient.proxy()
// — distinct from a direct-egress client so the log can call it out specifically.
function isProxyClient(entry: BotLog): boolean {
    return !!entry.remote_ip && entry.remote_ip.startsWith('proxy:');
}

function proxyHost(entry: BotLog): string {
    return entry.remote_ip?.replace(/^proxy:/, '') ?? '';
}

type RouteKind = 'proxy' | 'ipv6' | 'ipv4' | 'realip';

// Classify a row's egress route from remote_ip, shipped by the Java bot:
//   proxy:<host>  → Oxylabs edge-throttle fallback
//   an IPv6 addr  → per-account IPv6 source binding (duronto_v_7.1+)
//   an IPv4 addr  → the worker machine's public IPv4 egress
//   api.ivacbd.com (or unknown host) → direct Cloudflare egress, no specific IP
function routeChip(entry: BotLog): { kind: RouteKind; label: string; value: string } {
    if (isProxyClient(entry)) {
        return { kind: 'proxy', label: 'proxy', value: proxyHost(entry) };
    }

    const ip = resolvedIp(entry);

    if (ip === 'api.ivacbd.com' || ip === 'firebase.com') {
        return { kind: 'realip', label: 'real ip', value: '' };
    }

    if (ip.includes(':')) {
        return { kind: 'ipv6', label: 'IPv6', value: ip };
    }

    if (/^\d{1,3}(\.\d{1,3}){3}$/.test(ip)) {
        return { kind: 'ipv4', label: 'IPv4', value: ip };
    }

    return { kind: 'realip', label: 'real ip', value: ip };
}

function routeChipClass(kind: RouteKind): string {
    switch (kind) {
        case 'proxy':
            return 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-950/40 dark:text-fuchsia-400';
        case 'ipv6':
            return 'bg-sky-100 text-sky-700 dark:bg-sky-950/40 dark:text-sky-400';
        case 'ipv4':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400';
        default:
            return 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400';
    }
}

function routeChipTitle(entry: BotLog): string {
    const chip = routeChip(entry);
    switch (chip.kind) {
        case 'proxy':
            return `Routed through the Oxylabs edge-throttle fallback proxy (${chip.value})`;
        case 'ipv6':
            return `Egress bound to IPv6 source ${chip.value}`;
        case 'ipv4':
            return `Worker public IPv4 egress ${chip.value}`;
        default:
            return 'Direct egress through Cloudflare (no specific source IP)';
    }
}

function labelBadgeClass(label: string | null | undefined): string {
    if (!label) return '';
    if (label.startsWith('SLOT-CF')) return 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300';
    if (label.startsWith('SLOT-IODP')) return 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300';
    if (label.startsWith('SLOT')) return 'bg-violet-100 text-violet-800 dark:bg-violet-950/40 dark:text-violet-300';
    if (label.startsWith('OTP')) return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300';
    return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-900 dark:text-zinc-400';
}

const maxDuration = computed(() => Math.max(...entries.value.map((e) => e.duration_ms ?? 0), 1));

const avgDuration = computed(() => {
    const valid = entries.value.filter((e) => e.duration_ms != null);
    if (!valid.length) return null;
    return Math.round(valid.reduce((sum, e) => sum + (e.duration_ms ?? 0), 0) / valid.length);
});

function endpointRank(path: string): number {
    const p = path.toLowerCase();
    if (/fpsendotp|forgotpassword|sendotp/.test(p)) return 1;
    if (/signin|login/.test(p)) return 2;
    if (/verify/.test(p)) return 3;
    if (/reserveslot|reserve/.test(p)) return 4;
    if (/initiatepayment|payment/.test(p)) return 5;
    if (/iams\/api\/v1/.test(p)) return 0;
    return 99;
}

const sortedEndpoints = computed(() =>
    [...endpoints.value].sort((a, b) => {
        const ra = endpointRank(a.path);
        const rb = endpointRank(b.path);
        if (ra !== rb) return ra - rb;
        return b.count - a.count;
    })
);

const totalSummaryCount = computed(() => summary.value.reduce((s, r) => s + r.count, 0));
const maxSummaryCount = computed(() => Math.max(...summary.value.map((r) => r.count), 1));

const count429 = computed(() => summary.value.find((r) => r.status_code === 429)?.count ?? 0);
const countErrors = computed(() => summary.value.find((r) => r.status_code === null)?.count ?? 0);
const count2xx = computed(() => summary.value.filter((r) => r.status_code !== null && r.status_code >= 200 && r.status_code < 300).reduce((s, r) => s + r.count, 0));
const count4xx = computed(() => summary.value.filter((r) => r.status_code !== null && r.status_code >= 400 && r.status_code < 500).reduce((s, r) => s + r.count, 0));

async function loadSlots() {
    try {
        const { data } = await axios.get('/api/agent-slots');
        slots.value = data;
    } catch {}
}

async function loadPhones() {
    try {
        const params: Record<string, any> = {};
        if (filterSlot.value) params.slot_id = filterSlot.value;
        if (filterEndpoints.value.length) params.endpoints = filterEndpoints.value;
        if (filterStatusCode.value.trim()) params.status_code = filterStatusCode.value.trim();
        if (filterMethod.value) params.method = filterMethod.value;
        const { data } = await axios.get('/api/db-bot-logs/phones', { params });
        phones.value = data;
        // Drop any selected phones that no longer exist in the dataset
        const stillPresent = filterPhones.value.filter((p) => data.includes(p));
        if (stillPresent.length !== filterPhones.value.length) {
            filterPhones.value = stillPresent;
        }
    } catch {}
}

async function loadSummary() {
    try {
        const params: Record<string, any> = {};
        if (filterSlot.value) params.slot_id = filterSlot.value;
        if (filterEndpoints.value.length) params.endpoints = filterEndpoints.value;
        const { data } = await axios.get('/api/db-bot-logs/summary', { params });
        summary.value = data;
    } catch {}
}

async function loadEndpoints() {
    try {
        const params: Record<string, string | number> = {};
        if (filterSlot.value) params.slot_id = filterSlot.value;
        const { data } = await axios.get('/api/db-bot-logs/endpoints', { params });
        endpoints.value = data;
    } catch {}
}

async function loadEntries(resetPage = true, silent = false) {
    if (resetPage) currentPage.value = 1;
    if (silent) {
        backgroundRefreshing.value = true;
    } else {
        loadingEntries.value = true;
        expandedRow.value = null;
    }
    try {
        const params: Record<string, any> = {
            paginate: 1,
            per_page: perPage.value,
            page: currentPage.value,
            exclude_method: 'LOG',
            sort_by: sortBy.value,
            sort_dir: sortDir.value,
        };
        if (filterSlot.value) params.slot_id = filterSlot.value;
        if (filterPhones.value.length) params.phones = filterPhones.value;
        if (filterStatusCode.value.trim()) params.status_code = filterStatusCode.value.trim();
        if (filterEndpoints.value.length) params.endpoints = filterEndpoints.value;
        if (filterMethod.value) params.method = filterMethod.value;
        if (filterHasReservationId.value) params.has_reservation_id = 1;
        if (filterProxyOnly.value) params.proxy_only = 1;
        const { data } = await axios.get('/api/db-bot-logs', { params });
        entries.value = data.data ?? [];
        totalEntries.value = data.total ?? 0;
        lastPage.value = data.last_page ?? 1;
        currentPage.value = data.current_page ?? 1;
    } catch {
        if (!silent) {
            entries.value = [];
            totalEntries.value = 0;
            lastPage.value = 1;
        }
    } finally {
        loadingEntries.value = false;
        backgroundRefreshing.value = false;
    }
}

async function reloadAll(resetPage = true, silent = false) {
    await Promise.all([loadSummary(), loadEndpoints(), loadEntries(resetPage, silent), loadPhones()]);
}

function debouncedReload() {
    if (filterDebounce) clearTimeout(filterDebounce);
    filterDebounce = setTimeout(() => reloadAll(true), 350);
}

function goToPage(page: number) {
    if (page < 1 || page > lastPage.value || page === currentPage.value) return;
    currentPage.value = page;
    loadEntries(false);
}

function toggleRow(id: number) {
    expandedRow.value = expandedRow.value === id ? null : id;
}

function selectStatusCode(code: string) {
    filterStatusCode.value = filterStatusCode.value === code ? '' : code;
    reloadAll(true);
}

function selectEndpoint(path: string) {
    const idx = filterEndpoints.value.indexOf(path);
    if (idx >= 0) {
        filterEndpoints.value = filterEndpoints.value.filter((p) => p !== path);
    } else {
        filterEndpoints.value = [...filterEndpoints.value, path];
    }
    reloadAll(true);
}

function togglePhone(phone: string) {
    if (filterPhones.value.includes(phone)) {
        filterPhones.value = filterPhones.value.filter((p) => p !== phone);
    } else {
        filterPhones.value = [...filterPhones.value, phone];
    }
    reloadAll(true);
}

function openApiTester(phone: string | null) {
    if (!phone) {
        return;
    }
    router.visit(`/api-tester?phone=${encodeURIComponent(phone)}`);
}

function addPhoneFromSelect(event: Event) {
    const phone = (event.target as HTMLSelectElement).value;
    if (phone && !filterPhones.value.includes(phone)) {
        filterPhones.value = [...filterPhones.value, phone];
        reloadAll(true);
    }
    (event.target as HTMLSelectElement).value = '';
}

function toggleSort(col: 'logged_at' | 'received_at' | 'duration_ms') {
    if (sortBy.value === col) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortBy.value = col;
        sortDir.value = col === 'duration_ms' ? 'desc' : 'desc';
    }
    loadEntries(false);
}

async function deleteLogEntry(entryId: number) {
    deletingId.value = entryId;
    try {
        await axios.delete(`/api/db-bot-logs/${entryId}`);
        entries.value = entries.value.filter((e) => e.id !== entryId);
        toast.success('Log entry deleted');
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? 'Failed to delete log entry');
    } finally {
        deletingId.value = null;
    }
}

async function deleteAllEntries() {
    const isFiltered = filterStatusCode.value || filterPhones.value.length > 0 || filterSlot.value || filterEndpoints.value.length > 0 || filterMethod.value || filterHasReservationId.value || filterProxyOnly.value;
    const scope = isFiltered ? `filtered ${totalEntries.value}` : `all ${totalEntries.value}`;
    if (!confirm(`Delete ${scope} log entries? This cannot be undone.`)) return;

    deletingAll.value = true;
    try {
        const params: Record<string, any> = { exclude_method: 'LOG' };
        if (filterSlot.value) params.slot_id = filterSlot.value;
        if (filterPhones.value.length) params.phones = filterPhones.value;
        if (filterStatusCode.value.trim()) params.status_code = filterStatusCode.value.trim();
        if (filterEndpoints.value.length) params.endpoints = filterEndpoints.value;
        if (filterMethod.value) params.method = filterMethod.value;
        if (filterHasReservationId.value) params.has_reservation_id = 1;
        if (filterProxyOnly.value) params.proxy_only = 1;
        const response = await axios.delete('/api/db-bot-logs/destroy-all', { params });
        await reloadAll(true);
        toast.success(response.data.message ?? 'Entries deleted');
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? 'Failed to delete entries');
    } finally {
        deletingAll.value = false;
    }
}

function formatJson(raw: string | null): string {
    if (!raw) return '';
    try {
        return JSON.stringify(JSON.parse(raw), null, 2);
    } catch {
        return raw;
    }
}

function formatTime(dt: string): string {
    return new Date(dt).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false, timeZone: 'Asia/Dhaka' });
}

function formatSentTime(dt: string, durationMs: number | null): string {
    if (!durationMs) return formatTime(dt);
    return formatTime(new Date(new Date(dt).getTime() - durationMs).toISOString());
}

function getReservationId(entry: BotLog): string | null {
    if (!entry.url?.includes('reserve-slot') || !entry.response_body) return null;
    try {
        const parsed = JSON.parse(entry.response_body);
        return parsed?.reservationId ?? null;
    } catch {
        return null;
    }
}

function isOtpVerified(entry: BotLog): boolean {
    if (!entry.url?.includes('verifySigninOtp') || entry.status_code !== 200 || !entry.response_body) return false;
    try {
        const parsed = JSON.parse(entry.response_body);
        return parsed?.verified === true || parsed?.data?.verified === true;
    } catch {
        return false;
    }
}

function isSlotReservedSuccess(entry: BotLog): boolean {
    if (!entry.url?.includes('reserve-slot') || entry.status_code !== 200 || !entry.response_body) return false;
    try {
        const parsed = JSON.parse(entry.response_body);
        const status = parsed?.status;
        return !!parsed?.reservationId && status !== 'FULL' && status !== 'NOT_OPEN' && status !== 'SLOT_NOT_PREPARED';
    } catch {
        return false;
    }
}

function isPaymentSuccess(entry: BotLog): boolean {
    const url = entry.url ?? '';
    // dg-epay's real URL is /payment/{paymentConfigId}/dg-epay/initiate — the UUID sits between
    // "payment/" and "dg-epay/initiate", so match the suffix, not the old contiguous literal.
    if (!url.includes('payment/ssl/initiate') && !url.includes('dg-epay/initiate')) return false;
    if (entry.status_code !== 200 && entry.status_code !== 201) return false;
    if (!entry.response_body) return false;
    try {
        const parsed = JSON.parse(entry.response_body);
        if (parsed?.successFlag === false) return null;
        const data = parsed?.data ?? {};
        const link = data.redirectGatewayURL || data.GatewayPageURL || data.webview_url;
        return typeof link === 'string' && link.length > 0;
    } catch {
        return false;
    }
}

// IVAC's /file/upload_file returns HTTP 200 + successFlag:true even when it rejects the file
// (name/email/passport mismatch etc.) — the real result is data.error[], empty = accepted.
function isUploadFileSuccess(entry: BotLog): boolean {
    if ((!entry.url?.includes('upload_file') && !entry.url?.includes('upload-file')) || entry.status_code !== 200 || !entry.response_body) return false;
    try {
        const parsed = JSON.parse(entry.response_body);
        const errors = parsed?.data?.error;
        return Array.isArray(errors) && errors.length === 0;
    } catch {
        return false;
    }
}

function getUploadFileErrorCount(entry: BotLog): number | null {
    if ((!entry.url?.includes('upload_file') && !entry.url?.includes('upload-file')) || entry.status_code !== 200 || !entry.response_body) return null;
    try {
        const parsed = JSON.parse(entry.response_body);
        const errors = parsed?.data?.error;
        return Array.isArray(errors) && errors.length > 0 ? errors.length : null;
    } catch {
        return null;
    }
}

async function fetchOtpCounts() {
    try {
        const { data } = await axios.get('/api/otps/count');
        otpCounts.value = data;
    } catch {}
}

onMounted(() => {
    loadSlots();
    reloadAll();
    loadPhones();
    fetchOtpCounts();

    if (autoRefresh.value) {
        startAutoRefresh();
    }
});

onUnmounted(() => {
    if (autoRefreshTimer) clearInterval(autoRefreshTimer);
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" full-width>
        <Head title="Log Analysis" />

        <div class="flex h-full w-full flex-1 flex-col gap-4 p-3 sm:p-6">

            <!-- Header -->
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-400 to-blue-600 shadow-sm shadow-indigo-500/30">
                        <BarChart2 class="h-5 w-5 text-white" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Log Analysis</h1>
                        <p class="text-muted-foreground mt-0.5 text-sm">Analyse API call logs from all workers</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        @click="reloadAll(false)"
                        :disabled="loadingEntries"
                        class="gap-1.5"
                    >
                        <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': loadingEntries }" />
                        {{ loadingEntries ? 'Loading…' : 'Refresh' }}
                    </Button>
                    <button
                        @click="toggleAutoRefresh"
                        :class="[
                            'flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-xs font-medium transition-colors',
                            autoRefresh
                                ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-500'
                                : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800',
                        ]"
                    >
                        <Timer class="h-3.5 w-3.5" :class="{ 'animate-pulse': autoRefresh }" />
                        {{ autoRefresh ? `Auto (${autoRefreshInterval}s)` : 'Auto Refresh' }}
                        <span v-if="backgroundRefreshing" class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse" />
                    </button>
                    <select
                        v-if="autoRefresh"
                        v-model.number="autoRefreshInterval"
                        @change="() => { if (autoRefreshTimer) clearInterval(autoRefreshTimer); autoRefreshTimer = setInterval(() => reloadAll(false, true), autoRefreshInterval * 1000); }"
                        class="h-8 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 text-xs text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                    >
                        <option :value="3">3s</option>
                        <option :value="5">5s</option>
                        <option :value="10">10s</option>
                        <option :value="30">30s</option>
                    </select>
                </div>
            </div>

            <!-- Main grid: table area + right sidebar -->
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-[1fr_360px]">

                <!-- LEFT: filters + table -->
                <div class="flex min-w-0 flex-col gap-4">

                    <!-- Filter Controls -->
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                        <div class="flex flex-wrap items-end gap-3 px-4 py-3">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Worker</label>
                                <select
                                    v-model="filterSlot"
                                    @change="reloadAll(true)"
                                    class="h-9 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 py-1 text-xs text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                >
                                    <option value="">All Workers</option>
                                    <option v-for="slot in slots" :key="slot.id" :value="slot.id">{{ slot.name }}</option>
                                </select>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                    Phone
                                    <span v-if="filterPhones.length" class="text-emerald-500">· {{ filterPhones.length }} selected</span>
                                </label>
                                <select
                                    @change="addPhoneFromSelect"
                                    class="h-9 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 py-1 text-xs text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 w-40"
                                >
                                    <option value="">+ Add Phone</option>
                                    <option v-for="phone in phones" :key="phone" :value="phone" :disabled="filterPhones.includes(phone)">{{ phone }}</option>
                                </select>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Status Code</label>
                                <input
                                    v-model="filterStatusCode"
                                    @input="debouncedReload"
                                    placeholder="429"
                                    class="h-9 w-24 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 py-1 text-xs text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Per Page</label>
                                <select
                                    v-model.number="perPage"
                                    @change="loadEntries(true)"
                                    class="h-9 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 py-1 text-xs text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                >
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                    <option :value="100">100</option>
                                    <option :value="200">200</option>
                                    <option :value="500">500</option>
                                </select>
                            </div>
                            <Button
                                v-if="isSuperAdmin"
                                size="sm"
                                @click="deleteAllEntries"
                                :disabled="totalEntries === 0 || deletingAll"
                                variant="outline"
                                class="gap-1.5 text-red-600 border-red-600 hover:text-red-700 hover:border-red-700 dark:text-red-400 dark:border-red-400 dark:hover:text-red-300"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                                {{ deletingAll ? 'Deleting...' : 'Delete All' }}
                            </Button>
                        </div>
                    </div>

                    <!-- Phone quick-filter pills -->
                    <div v-if="phones.length > 0" class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                        <div class="flex flex-wrap items-center gap-2 px-4 py-3">
                            <span class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-500 mr-1">
                                Phones
                                <span v-if="filterEndpoints.length === 1" class="text-blue-500 ml-1">· {{ filterEndpoints[0] }}</span>
                                <span v-else-if="filterEndpoints.length > 1" class="text-blue-500 ml-1">· {{ filterEndpoints.length }} endpoints</span>
                            </span>
                            <button
                                v-for="phone in phones"
                                :key="phone"
                                @click="togglePhone(phone)"
                                :class="[
                                    'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-[10px] font-mono font-medium transition-colors border',
                                    filterPhones.includes(phone)
                                        ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                                        : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800',
                                ]"
                            >
                                {{ phone }}
                                <span v-if="otpCounts[phone]" class="font-bold bg-amber-400 text-zinc-900 rounded px-1.5 py-0 tabular-nums" style="font-size:9px">{{ otpCounts[phone] }}</span>
                            </button>
                            <button
                                v-if="filterPhones.length"
                                @click="filterPhones = []; reloadAll(true)"
                                class="rounded-md px-2.5 py-1 text-xs font-medium border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            >
                                ✕ Clear
                            </button>
                        </div>
                    </div>

                    <!-- Status + Method + Has-Slot-ID quick filters -->
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                        <div class="flex flex-wrap items-center gap-2 px-4 py-3">
                            <span v-if="summary.length > 0" class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-500 mr-1">Status</span>
                            <button
                                v-for="row in summary"
                                :key="row.status_code ?? 'null'"
                                @click="selectStatusCode(row.status_code != null ? String(row.status_code) : '')"
                                :class="[
                                    'rounded-md px-2.5 py-1 text-xs font-medium transition-colors border',
                                    filterStatusCode === String(row.status_code ?? '')
                                        ? statusBadgeClass(row.status_code, row.status_code === null ? 'error' : null)
                                        : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800',
                                ]"
                            >
                                {{ row.status_code ?? 'ERR' }} <span class="opacity-60 ml-1">×{{ row.count }}</span>
                            </button>
                            <button
                                v-if="filterStatusCode"
                                @click="filterStatusCode = ''; reloadAll(true)"
                                class="rounded-md px-2.5 py-1 text-xs font-medium border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            >
                                ✕ Clear
                            </button>

                            <span class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-500 ml-4 mr-1">Method</span>
                            <button
                                @click="filterMethod = filterMethod === 'GET' ? '' : 'GET'; reloadAll(true)"
                                :class="[
                                    'rounded-md px-2.5 py-1 text-xs font-medium transition-colors border',
                                    filterMethod === 'GET'
                                        ? 'border-blue-500 bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400'
                                        : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800',
                                ]"
                            >
                                GET
                            </button>
                            <button
                                @click="filterMethod = filterMethod === 'POST' ? '' : 'POST'; reloadAll(true)"
                                :class="[
                                    'rounded-md px-2.5 py-1 text-xs font-medium transition-colors border',
                                    filterMethod === 'POST'
                                        ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                                        : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800',
                                ]"
                            >
                                POST
                            </button>

                            <span class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-500 ml-4 mr-1">Filter</span>
                            <button
                                @click="filterHasReservationId = !filterHasReservationId; reloadAll(true)"
                                :class="[
                                    'rounded-md px-2.5 py-1 text-xs font-medium transition-colors border',
                                    filterHasReservationId
                                        ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                                        : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800',
                                ]"
                            >
                                ✓ Has Slot ID
                            </button>
                            <button
                                @click="filterProxyOnly = !filterProxyOnly; reloadAll(true)"
                                :class="[
                                    'inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-medium transition-colors border',
                                    filterProxyOnly
                                        ? 'border-fuchsia-500 bg-fuchsia-50 text-fuchsia-700 dark:bg-fuchsia-950/40 dark:text-fuchsia-400'
                                        : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800',
                                ]"
                                title="Show only requests the bot routed through the Oxylabs edge-throttle fallback proxy"
                            >
                                <ShieldAlert class="h-3 w-3" /> Proxy only
                            </button>
                        </div>
                    </div>

                    <template v-if="!loadingEntries && entries.length > 0">

                        <!-- Entry Table / Cards -->
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">

                            <!-- Mobile card list (< sm) -->
                            <div class="sm:hidden divide-y divide-zinc-200 dark:divide-zinc-800">
                                <template v-for="(entry, index) in entries" :key="entry.id">
                                    <div
                                        @click="toggleRow(entry.id)"
                                        class="cursor-pointer px-3 py-2.5 transition-colors group"
                                        :class="isSlotReservedSuccess(entry)
                                            ? 'bg-emerald-100/70 dark:bg-emerald-900/30 hover:bg-emerald-200/70 dark:hover:bg-emerald-900/50'
                                            : isPaymentSuccess(entry)
                                            ? 'bg-blue-100/70 dark:bg-blue-900/30 hover:bg-blue-200/70 dark:hover:bg-blue-900/50'
                                            : isOtpVerified(entry)
                                            ? 'bg-yellow-200 dark:bg-yellow-900/60 hover:bg-yellow-300 dark:hover:bg-yellow-900/80'
                                            : isUploadFileSuccess(entry)
                                            ? 'bg-purple-100/70 dark:bg-purple-900/30 hover:bg-purple-200/70 dark:hover:bg-purple-900/50'
                                            : getUploadFileErrorCount(entry)
                                            ? 'bg-red-100/70 dark:bg-red-900/30 hover:bg-red-200/70 dark:hover:bg-red-900/50'
                                            : entry.error_type
                                            ? 'bg-red-50/30 dark:bg-red-950/10 hover:bg-red-50/50 dark:hover:bg-red-950/20'
                                            : 'hover:bg-zinc-50 dark:hover:bg-zinc-900/50'"
                                    >
                                        <!-- Line 1: [time][method][status][phone ···][delete] — fixed columns align across all rows -->
                                        <div class="grid items-center gap-x-2" style="grid-template-columns: 4rem 2.75rem 3rem 1fr 1.5rem">
                                            <span class="font-mono tabular-nums text-[10px] text-zinc-500 dark:text-zinc-400">{{ formatTime(entry.logged_at) }}</span>
                                            <span class="font-mono font-semibold text-xs" :class="methodClass(entry.method)">{{ entry.method }}</span>
                                            <span :class="['rounded-md px-1.5 py-0.5 text-[10px] font-semibold text-center', statusBadgeClass(entry.status_code, entry.error_type)]">
                                                {{ entry.status_code ?? (entry.error_type ? 'ERR' : '—') }}
                                            </span>
                                            <button
                                                v-if="entry.account_phone"
                                                @click.stop="openApiTester(entry.account_phone)"
                                                class="font-mono text-[10px] text-blue-600 dark:text-blue-400 hover:underline truncate text-left"
                                                title="Open in API Tester"
                                            >{{ entry.account_phone }}</button>
                                            <span v-else class="font-mono text-[10px] text-zinc-500 dark:text-zinc-400 truncate"></span>
                                            <button
                                                v-if="isSuperAdmin"
                                                @click.stop="deleteLogEntry(entry.id)"
                                                :disabled="deletingId === entry.id"
                                                class="justify-self-end text-zinc-400 hover:text-red-600 dark:hover:text-red-400 disabled:opacity-50 p-0.5"
                                            >
                                                <Trash2 class="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                        <!-- Line 2: dim path prefix · bold action name · reservation badge -->
                                        <div class="mt-0.5 flex items-center min-w-0 pl-[4.5rem]">
                                            <span class="font-mono text-[9px] text-zinc-400 dark:text-zinc-600 truncate min-w-0">{{ endpointPathPrefix(entry.url) }}</span>
                                            <span class="font-mono text-[11px] font-semibold text-zinc-800 dark:text-zinc-200 shrink-0">{{ endpointAction(entry.url) }}</span>
                                            <span v-if="getReservationId(entry)" class="ml-2 shrink-0 font-mono text-[10px] text-emerald-700 dark:text-emerald-400 font-semibold whitespace-nowrap">✓ {{ getReservationId(entry) }}</span>
                                            <span v-if="isUploadFileSuccess(entry)" class="ml-2 shrink-0 font-mono text-[10px] text-purple-700 dark:text-purple-400 font-semibold whitespace-nowrap">✓ Uploaded</span>
                                            <span v-else-if="getUploadFileErrorCount(entry)" class="ml-2 shrink-0 font-mono text-[10px] text-red-700 dark:text-red-400 font-semibold whitespace-nowrap">{{ getUploadFileErrorCount(entry) }} error{{ getUploadFileErrorCount(entry) === 1 ? '' : 's' }}</span>
                                        </div>
                                        <!-- Line 3: worker (left) · duration + bypass IP (right) -->
                                        <div class="mt-1 flex items-center justify-between pl-[4.5rem]">
                                            <a
                                                v-if="entry.agent_slot"
                                                :href="`/slot-logs/${entry.agent_slot.id}`"
                                                @click.stop
                                                target="_blank"
                                                rel="noopener"
                                                class="rounded-md px-2 py-0.5 text-[10px] font-semibold bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/40 transition-colors"
                                            >{{ entry.agent_slot.name }}</a>
                                            <span v-else class="text-zinc-400 dark:text-zinc-600 text-[10px]">—</span>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-mono text-[10px] font-semibold" :class="routeChipClass(routeChip(entry).kind)" :title="routeChipTitle(entry)">
                                                    <ShieldAlert v-if="routeChip(entry).kind === 'proxy'" class="h-2.5 w-2.5" />{{ routeChip(entry).label }}<template v-if="routeChip(entry).value"> · {{ routeChip(entry).value }}</template>
                                                </span>
                                                <span v-if="entry.duration_ms != null" class="font-mono tabular-nums text-[10px]" :class="durationClass(entry.duration_ms)">{{ formatDuration(entry.duration_ms) }}</span>
                                                <span v-if="entry.account_phone" class="font-mono text-[10px] text-zinc-500 dark:text-zinc-400">JWT {{ fmtJwtExpiresIn(entry.jwt_expires_at) }}</span>
                                            </div>
                                        </div>
                                        <!-- Expanded detail -->
                                        <div v-if="expandedRow === entry.id" class="mt-2 space-y-2">
                                            <div>
                                                <div class="font-semibold text-zinc-700 dark:text-zinc-300 uppercase tracking-widest mb-1 text-[10px]">Request Body</div>
                                                <pre class="rounded-md bg-zinc-100 dark:bg-zinc-800 p-2 font-mono overflow-x-auto max-h-36 whitespace-pre-wrap break-all text-[10px] text-zinc-700 dark:text-zinc-300">{{ formatJson(entry.request_body) || '(empty)' }}</pre>
                                            </div>
                                            <div>
                                                <div class="font-semibold text-zinc-700 dark:text-zinc-300 uppercase tracking-widest mb-1 text-[10px]">Response Body</div>
                                                <pre class="rounded-md bg-zinc-100 dark:bg-zinc-800 p-2 font-mono overflow-x-auto max-h-36 whitespace-pre-wrap break-all text-[10px] text-zinc-700 dark:text-zinc-300">{{ formatJson(entry.response_body) || '(empty)' }}</pre>
                                            </div>
                                            <div v-if="entry.error_message" class="rounded-md border border-red-300 dark:border-red-900/50 bg-red-50 dark:bg-red-950/30 px-3 py-2 text-[11px] text-red-700 dark:text-red-400">
                                                <span class="font-semibold">{{ entry.error_type }}</span>: {{ entry.error_message }}
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <!-- Desktop table (>= sm) -->
                            <div class="hidden sm:block overflow-x-auto">
                                <table class="min-w-full text-xs whitespace-nowrap">
                                    <thead class="bg-zinc-50 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800">
                                        <tr>
                                            <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-4 py-3 text-[11px] uppercase tracking-widest w-10 border-r border-zinc-200 dark:border-zinc-700">S/N</th>
                                            <th class="px-4 py-3 w-16">
                                                <button @click="toggleSort('logged_at')" class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest transition-colors"
                                                    :class="sortBy === 'logged_at' ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-zinc-100'">
                                                    Time
                                                    <ArrowUp v-if="sortBy === 'logged_at' && sortDir === 'asc'" class="h-3 w-3" />
                                                    <ArrowDown v-else-if="sortBy === 'logged_at' && sortDir === 'desc'" class="h-3 w-3" />
                                                    <ArrowUpDown v-else class="h-3 w-3 opacity-40" />
                                                </button>
                                            </th>
                                            <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest w-12">Method</th>
                                            <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest">Endpoint</th>
                                            <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest w-28">Route</th>
                                            <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest w-24">Worker</th>
                                            <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest w-16">Status</th>
                                            <th class="px-3 py-3 w-24">
                                                <button @click="toggleSort('received_at')" class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest transition-colors"
                                                    :class="sortBy === 'received_at' ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-zinc-100'">
                                                    Received
                                                    <ArrowUp v-if="sortBy === 'received_at' && sortDir === 'asc'" class="h-3 w-3" />
                                                    <ArrowDown v-else-if="sortBy === 'received_at' && sortDir === 'desc'" class="h-3 w-3" />
                                                    <ArrowUpDown v-else class="h-3 w-3 opacity-40" />
                                                </button>
                                            </th>
                                            <th class="px-3 py-3 w-24">
                                                <button @click="toggleSort('duration_ms')" class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest transition-colors"
                                                    :class="sortBy === 'duration_ms' ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-zinc-100'">
                                                    Duration
                                                    <ArrowUp v-if="sortBy === 'duration_ms' && sortDir === 'asc'" class="h-3 w-3" />
                                                    <ArrowDown v-else-if="sortBy === 'duration_ms' && sortDir === 'desc'" class="h-3 w-3" />
                                                    <ArrowUpDown v-else class="h-3 w-3 opacity-40" />
                                                </button>
                                            </th>
                                            <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest w-24">Phone</th>
                                            <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest w-16">OTP</th>
                                            <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest w-20">JWT</th>
                                            <th class="w-8"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                        <template v-for="(entry, index) in entries" :key="entry.id">
                                            <tr
                                                @click="toggleRow(entry.id)"
                                                class="cursor-pointer transition-colors group"
                                                :class="isSlotReservedSuccess(entry)
                                                    ? 'bg-emerald-100/70 dark:bg-emerald-900/30 hover:bg-emerald-200/70 dark:hover:bg-emerald-900/50'
                                                    : isPaymentSuccess(entry)
                                                    ? 'bg-blue-100/70 dark:bg-blue-900/30 hover:bg-blue-200/70 dark:hover:bg-blue-900/50'
                                                    : isOtpVerified(entry)
                                                    ? 'bg-yellow-200 dark:bg-yellow-900/60 hover:bg-yellow-300 dark:hover:bg-yellow-900/80'
                                                    : isUploadFileSuccess(entry)
                                                    ? 'bg-purple-100/70 dark:bg-purple-900/30 hover:bg-purple-200/70 dark:hover:bg-purple-900/50'
                                                    : getUploadFileErrorCount(entry)
                                                    ? 'bg-red-100/70 dark:bg-red-900/30 hover:bg-red-200/70 dark:hover:bg-red-900/50'
                                                    : entry.error_type
                                                    ? 'bg-red-50/30 dark:bg-red-950/10 hover:bg-zinc-100 dark:hover:bg-zinc-800/60'
                                                    : index % 2 === 0
                                                    ? 'bg-white dark:bg-zinc-950 hover:bg-zinc-50 dark:hover:bg-zinc-900/50'
                                                    : 'bg-zinc-50/70 dark:bg-zinc-900/40 hover:bg-zinc-100 dark:hover:bg-zinc-800/60'"
                                            >
                                                <td class="px-4 py-2.5 font-mono text-zinc-400 dark:text-zinc-600 tabular-nums text-[10px] whitespace-nowrap border-r border-zinc-200 dark:border-zinc-700">{{ (currentPage - 1) * perPage + index + 1 }}</td>
                                                <td class="px-4 py-2.5 font-mono text-zinc-500 dark:text-zinc-400 tabular-nums text-[10px] whitespace-nowrap">{{ formatSentTime(entry.logged_at, entry.duration_ms) }}</td>
                                                <td class="px-3 py-2.5 font-mono font-semibold text-xs whitespace-nowrap" :class="methodClass(entry.method)">{{ entry.method }}</td>
                                                <td class="px-3 py-2.5 truncate max-w-sm text-xs whitespace-nowrap">
                                                    <span class="inline-flex items-center gap-1.5 text-zinc-600 dark:text-zinc-400">
                                                        <component :is="endpointIcon(entry.url)" class="w-3 h-3 shrink-0 opacity-60" />
                                                        {{ endpointAction(entry.url) }}
                                                    </span>
                                                    <span v-if="getReservationId(entry)" class="ml-2 font-mono text-[10px] text-emerald-700 dark:text-emerald-400 font-semibold">
                                                        ✓ {{ getReservationId(entry) }}
                                                    </span>
                                                    <span v-if="isUploadFileSuccess(entry)" class="ml-2 font-mono text-[10px] text-purple-700 dark:text-purple-400 font-semibold">
                                                        ✓ Uploaded
                                                    </span>
                                                    <span v-else-if="getUploadFileErrorCount(entry)" class="ml-2 font-mono text-[10px] text-red-700 dark:text-red-400 font-semibold">
                                                        {{ getUploadFileErrorCount(entry) }} error{{ getUploadFileErrorCount(entry) === 1 ? '' : 's' }}
                                                    </span>
                                                </td>
                                                <td class="px-3 py-2.5 whitespace-nowrap">
                                                    <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-mono text-[10px] font-semibold" :class="routeChipClass(routeChip(entry).kind)" :title="routeChipTitle(entry)">
                                                        <ShieldAlert v-if="routeChip(entry).kind === 'proxy'" class="h-2.5 w-2.5" />{{ routeChip(entry).label }}<template v-if="routeChip(entry).value"> · {{ routeChip(entry).value }}</template>
                                                    </span>
                                                </td>
                                                <td class="px-3 py-2.5 whitespace-nowrap" @click.stop>
                                                    <a
                                                        v-if="entry.agent_slot"
                                                        :href="`/slot-logs/${entry.agent_slot.id}`"
                                                        target="_blank"
                                                        rel="noopener"
                                                        class="rounded-md px-2 py-1 text-[10px] font-semibold bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/40 transition-colors"
                                                    >{{ entry.agent_slot.name }}</a>
                                                    <span v-else class="text-zinc-400 dark:text-zinc-600 text-[10px]">—</span>
                                                </td>
                                                <td class="px-3 py-2.5 whitespace-nowrap">
                                                    <span :class="['rounded-md px-2 py-1 text-[10px] font-semibold', statusBadgeClass(entry.status_code, entry.error_type)]">
                                                        {{ entry.status_code ?? (entry.error_type ? 'ERR' : '—') }}
                                                    </span>
                                                </td>
                                                <td class="px-3 py-2.5 font-mono text-emerald-600 dark:text-emerald-400 tabular-nums text-[10px] whitespace-nowrap">{{ formatTime(entry.logged_at) }}</td>
                                                <td class="px-3 py-2.5 whitespace-nowrap">
                                                    <span v-if="entry.duration_ms != null" class="font-mono tabular-nums text-[10px]" :class="durationClass(entry.duration_ms)">{{ formatDuration(entry.duration_ms) }}</span>
                                                    <span v-else class="text-zinc-400 dark:text-zinc-600 text-[10px]">—</span>
                                                </td>
                                                <td class="px-3 py-2.5 font-mono text-[10px] whitespace-nowrap" @click.stop>
                                                    <button
                                                        v-if="entry.account_phone"
                                                        @click="openApiTester(entry.account_phone)"
                                                        class="text-blue-600 dark:text-blue-400 underline underline-offset-2"
                                                        title="Open in API Tester"
                                                    >{{ entry.account_phone }}</button>
                                                    <span v-else class="text-zinc-500 dark:text-zinc-400">—</span>
                                                </td>
                                                <td class="px-3 py-2.5 whitespace-nowrap">
                                                    <span v-if="entry.account_phone && otpCounts[entry.account_phone]" class="font-mono font-bold text-xs bg-amber-400 text-zinc-900 rounded px-2 py-0.5 tabular-nums">{{ otpCounts[entry.account_phone] }}</span>
                                                    <span v-else class="text-zinc-400 dark:text-zinc-600 text-[10px]">—</span>
                                                </td>
                                                <td class="px-3 py-2.5 font-mono tabular-nums text-[10px] whitespace-nowrap">
                                                    <span v-if="entry.account_phone" :class="fmtJwtExpiresIn(entry.jwt_expires_at) === 'expired' ? 'text-red-600 dark:text-red-400' : 'text-zinc-600 dark:text-zinc-400'">{{ fmtJwtExpiresIn(entry.jwt_expires_at) }}</span>
                                                    <span v-else class="text-zinc-400 dark:text-zinc-600">—</span>
                                                </td>
                                                <td class="px-3 py-2.5 text-right whitespace-nowrap" @click.stop>
                                                    <button
                                                        v-if="isSuperAdmin"
                                                        @click="deleteLogEntry(entry.id)"
                                                        :disabled="deletingId === entry.id"
                                                        class="opacity-0 group-hover:opacity-100 transition-opacity text-zinc-400 hover:text-red-600 dark:hover:text-red-400 disabled:opacity-50 p-1"
                                                        title="Delete log entry"
                                                    >
                                                        <Trash2 class="w-3.5 h-3.5" />
                                                    </button>
                                                </td>
                                            </tr>
                                            <!-- Expanded detail -->
                                            <tr v-if="expandedRow === entry.id" class="bg-zinc-50 dark:bg-zinc-900/40">
                                                <td colspan="13" class="px-4 py-3">
                                                    <div class="grid grid-cols-2 gap-4 text-xs">
                                                        <div>
                                                            <div class="font-semibold text-zinc-700 dark:text-zinc-300 uppercase tracking-widest mb-2 text-[10px]">Request Body</div>
                                                            <pre class="rounded-md bg-zinc-100 dark:bg-zinc-800 p-3 font-mono overflow-x-auto max-h-48 whitespace-pre-wrap break-all text-[10px] text-zinc-700 dark:text-zinc-300">{{ formatJson(entry.request_body) || '(empty)' }}</pre>
                                                        </div>
                                                        <div>
                                                            <div class="font-semibold text-zinc-700 dark:text-zinc-300 uppercase tracking-widest mb-2 text-[10px]">Response Body</div>
                                                            <pre class="rounded-md bg-zinc-100 dark:bg-zinc-800 p-3 font-mono overflow-x-auto max-h-48 whitespace-pre-wrap break-all text-[10px] text-zinc-700 dark:text-zinc-300">{{ formatJson(entry.response_body) || '(empty)' }}</pre>
                                                        </div>
                                                    </div>
                                                    <div v-if="entry.error_message" class="mt-3 rounded-md border border-red-300 dark:border-red-900/50 bg-red-50 dark:bg-red-950/30 px-4 py-3 text-[11px] text-red-700 dark:text-red-400">
                                                        <span class="font-semibold">{{ entry.error_type }}</span>: {{ entry.error_message }}
                                                    </div>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <div v-if="lastPage > 1" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 px-4 py-3">
                            <div class="text-xs text-zinc-600 dark:text-zinc-400">
                                Page <span class="font-semibold text-zinc-900 dark:text-white">{{ currentPage }}</span> of
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ lastPage }}</span>
                                <span class="ml-2 opacity-70">({{ totalEntries }} total)</span>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <Button size="sm" variant="outline" @click="goToPage(1)" :disabled="currentPage === 1 || loadingEntries">First</Button>
                                <Button size="sm" variant="outline" @click="goToPage(currentPage - 1)" :disabled="currentPage === 1 || loadingEntries">Prev</Button>
                                <Button size="sm" variant="outline" @click="goToPage(currentPage + 1)" :disabled="currentPage === lastPage || loadingEntries">Next</Button>
                                <Button size="sm" variant="outline" @click="goToPage(lastPage)" :disabled="currentPage === lastPage || loadingEntries">Last</Button>
                            </div>
                        </div>
                    </template>

                    <div v-else-if="!loadingEntries" class="text-center py-12">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            No log entries match your filters.
                        </p>
                    </div>
                </div>

                <!-- RIGHT SIDEBAR: stats + endpoint filters -->
                <aside class="flex flex-col gap-4 xl:sticky xl:top-4 xl:self-start">

                    <!-- Statistics cards -->
                    <div v-if="summary.length > 0" class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                        <div class="border-b border-zinc-200 dark:border-zinc-800 px-4 py-3">
                            <p class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-500">Statistics</p>
                        </div>
                        <div class="grid grid-cols-2 gap-px bg-zinc-200 dark:bg-zinc-800">
                            <div class="bg-white dark:bg-zinc-950 px-4 py-3">
                                <p class="text-[10px] font-medium text-zinc-600 dark:text-zinc-400 mb-0.5">Total Logs</p>
                                <p class="text-lg font-bold text-zinc-900 dark:text-white tabular-nums">{{ totalSummaryCount.toLocaleString() }}</p>
                            </div>
                            <div class="bg-white dark:bg-zinc-950 px-4 py-3">
                                <p class="text-[10px] font-medium text-zinc-600 dark:text-zinc-400 mb-0.5">Avg Duration</p>
                                <p class="text-lg font-bold tabular-nums" :class="durationClass(avgDuration)">
                                    {{ avgDuration != null ? formatDuration(avgDuration) : '—' }}
                                </p>
                            </div>
                            <div class="bg-white dark:bg-zinc-950 px-4 py-3">
                                <p class="text-[10px] font-medium text-emerald-600 dark:text-emerald-400 mb-0.5">2xx Success</p>
                                <p class="text-lg font-bold text-emerald-600 dark:text-emerald-400 tabular-nums">{{ count2xx.toLocaleString() }}</p>
                            </div>
                            <div class="bg-white dark:bg-zinc-950 px-4 py-3">
                                <p class="text-[10px] font-medium text-red-600 dark:text-red-400 mb-0.5">4xx / 5xx</p>
                                <p class="text-lg font-bold text-red-600 dark:text-red-400 tabular-nums">{{ count4xx.toLocaleString() }}</p>
                            </div>
                        </div>

                        <!-- Status code bar chart -->
                        <div class="border-t border-zinc-200 dark:border-zinc-800 px-4 py-3">
                            <p class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-500 mb-2">Status Distribution</p>
                            <div class="flex items-end gap-1 h-20">
                                <div
                                    v-for="row in summary"
                                    :key="row.status_code ?? 'null'"
                                    class="flex flex-col items-center gap-0.5 flex-1 min-w-0 cursor-pointer group"
                                    :title="`${row.status_code ?? 'ERROR'}: ${row.count} calls${row.avg_duration_ms ? ', avg ' + formatDuration(Math.round(row.avg_duration_ms)) : ''}`"
                                    @click="selectStatusCode(row.status_code != null ? String(row.status_code) : '')"
                                >
                                    <span class="text-[9px] font-mono tabular-nums" :class="statusTextColor(row.status_code)">
                                        {{ row.count }}
                                    </span>
                                    <div class="w-full rounded-t transition-opacity" :class="[statusBarColor(row.status_code), filterStatusCode === String(row.status_code ?? '') ? 'opacity-100' : 'opacity-70 group-hover:opacity-90']"
                                        :style="{ height: `${Math.max(4, (row.count / maxSummaryCount) * 50)}px` }"
                                    ></div>
                                    <span class="text-[9px] font-mono truncate w-full text-center" :class="statusTextColor(row.status_code)">
                                        {{ row.status_code ?? 'ERR' }}
                                    </span>
                                </div>
                            </div>
                            <button
                                v-if="filterStatusCode"
                                @click="filterStatusCode = ''; reloadAll(true)"
                                class="mt-2 w-full rounded-md px-2.5 py-1 text-[10px] font-medium border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            >
                                ✕ Clear status filter ({{ filterStatusCode }})
                            </button>
                        </div>
                    </div>

                    <!-- Endpoint filters (vertical list, multi-select) -->
                    <div v-if="endpoints.length > 0" class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                        <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 px-4 py-3">
                            <div class="flex items-center gap-2">
                                <p class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-500">Endpoints</p>
                                <span v-if="filterEndpoints.length > 0" class="rounded-full bg-blue-100 dark:bg-blue-950/40 text-blue-700 dark:text-blue-400 text-[9px] font-semibold px-1.5 py-0.5 tabular-nums">{{ filterEndpoints.length }}</span>
                            </div>
                            <button
                                v-if="filterEndpoints.length > 0"
                                @click="filterEndpoints = []; reloadAll(true)"
                                class="text-[10px] text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300"
                            >
                                ✕ Clear
                            </button>
                        </div>
                        <div class="max-h-96 overflow-y-auto">
                            <button
                                v-for="ep in sortedEndpoints"
                                :key="ep.path"
                                @click="selectEndpoint(ep.path)"
                                :class="[
                                    'flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-[10px] font-mono transition-colors border-b border-zinc-100 dark:border-zinc-900 last:border-b-0',
                                    filterEndpoints.includes(ep.path)
                                        ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400'
                                        : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-900',
                                ]"
                                :title="ep.path"
                            >
                                <span class="flex items-center gap-1.5 truncate flex-1 min-w-0">
                                    <span v-if="filterEndpoints.includes(ep.path)" class="shrink-0 text-blue-500 dark:text-blue-400">✓</span>
                                    <span class="truncate">{{ ep.path }}</span>
                                </span>
                                <span class="shrink-0 tabular-nums opacity-70">×{{ ep.count }}</span>
                            </button>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
