<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { RefreshCw, ChevronDown, ChevronRight, Cloud, Terminal, Trash2, ArrowDownToLine, Copy, Check, BellRing, BellOff, CheckCircle2, CreditCard } from 'lucide-vue-next';
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface AgentSlot {
    id: number;
    name: string;
    status: 'online' | 'offline';
    worker_state: 'idle' | 'running' | 'stopping';
    ip_address: string | null;
    accounts_count: number;
    bypass_ip: { id: number; label: string; ip: string } | null;
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
}

const props = defineProps<{ slot: AgentSlot }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Bot Control', href: '/bot-control' },
    { title: props.slot.name, href: '#' },
];

const activeTab = ref('console');
const phones = ref<string[]>([]);
const consoleLogs = ref<BotLog[]>([]);
const consolePhoneFilter = ref('');
const consoleCurrentPage = ref(1);
const consoleLastPage = ref(1);
const consoleTotalCount = ref(0);
const phoneLogs = ref<Record<string, BotLog[]>>({});
const loading = ref(false);
const clearing = ref(false);
const autoScroll = ref(true);
const expandedIds = ref<Set<number>>(new Set());
const systemConsoleScrollEl = ref<HTMLElement | null>(null);
const phoneConsoleScrollEl = ref<HTMLElement | null>(null);
const apiScrollEl = ref<HTMLElement | null>(null);
const searchFilter = ref('');
const methodFilter = ref('');
const hideHead = ref(true);
const statusFilter = ref<number | null>(null);
const copiedId = ref<string | null>(null);
const REFRESH_INTERVAL_MS = 5_000;
let refreshTimer: ReturnType<typeof setInterval> | null = null;

const startTimer = () => {
    if (refreshTimer) clearInterval(refreshTimer);
    refreshTimer = setInterval(refresh, REFRESH_INTERVAL_MS);
};

const stopTimer = () => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
        refreshTimer = null;
    }
};

const onVisibilityChange = () => {
    if (document.visibilityState === 'visible') {
        refresh();
        startTimer();
    } else {
        stopTimer();
    }
};

const scrollToBottom = (el: HTMLElement | null) => {
    if (el) el.scrollTop = el.scrollHeight;
};

// ── Fetch ─────────────────────────────────────────────────────────────────────

const fetchPhones = async () => {
    try {
        const { data } = await axios.get('/api/db-bot-logs/phones', {
            params: { slot_id: props.slot.id },
        });
        const newPhones: string[] = data;
        // Add any new phones as tabs without removing existing ones mid-session
        for (const p of newPhones) {
            if (!phones.value.includes(p)) {
                phones.value.push(p);
            }
        }
    } catch (e) {
        console.error(e);
    }
};

const fetchConsoleLogs = async (resetPage = false) => {
    try {
        if (resetPage) consoleCurrentPage.value = 1;
        const { data } = await axios.get('/api/db-bot-logs', {
            params: {
                slot_id: props.slot.id,
                method: 'LOG',
                paginate: 1,
                per_page: 100,
                page: consoleCurrentPage.value,
            },
        });
        // Reverse to oldest-first so new entries appear at the bottom
        consoleLogs.value = (data.data as BotLog[]).reverse();
        consoleLastPage.value = data.last_page ?? 1;
        consoleTotalCount.value = data.total ?? 0;
        // Auto-scroll only when on the latest page (page 1 = most recent)
        if (autoScroll.value && consoleCurrentPage.value === 1) {
            await nextTick(() => {
                scrollToBottom(systemConsoleScrollEl.value);
                scrollToBottom(phoneConsoleScrollEl.value);
            });
        }
    } catch (e) {
        console.error(e);
    }
};

const goToConsolePage = async (page: number) => {
    if (page < 1 || page > consoleLastPage.value || page === consoleCurrentPage.value) return;
    consoleCurrentPage.value = page;
    await fetchConsoleLogs(false);
    if (page === 1 && autoScroll.value) {
        await nextTick(() => {
            scrollToBottom(systemConsoleScrollEl.value);
            scrollToBottom(phoneConsoleScrollEl.value);
        });
    }
};

const fetchPhoneLogs = async (phone: string) => {
    try {
        const { data } = await axios.get('/api/db-bot-logs', {
            params: { slot_id: props.slot.id, phone, exclude_status_codes: '502,504' },
        });
        // Oldest-first; keep API rows + POLLING console logs (OTP fetching hints)
        phoneLogs.value[phone] = (data.data as BotLog[])
            .filter(l => l.method !== 'LOG' || l.label === 'POLLING')
            .reverse();
        if (autoScroll.value) await nextTick(() => scrollToBottom(apiScrollEl.value));
    } catch (e) {
        console.error(e);
    }
};

const refresh = async () => {
    loading.value = true;
    try {
        await fetchPhones();
        // Only reset to page 1 on auto-refresh when already on page 1 (don't yank user off older pages)
        await fetchConsoleLogs(false);
        // Eager-load all phone logs so the slot-level banner sees reservations/payments across tabs
        await Promise.all(phones.value.map(p => fetchPhoneLogs(p)));
    } finally {
        loading.value = false;
    }
};

const clearLogs = async () => {
    if (!confirm(`Clear all logs for ${props.slot.name}?`)) return;
    clearing.value = true;
    try {
        await axios.delete('/api/db-bot-logs/clear', { params: { slot_id: props.slot.id } });
        consoleLogs.value = [];
        consoleCurrentPage.value = 1;
        consoleLastPage.value = 1;
        consoleTotalCount.value = 0;
        consolePhoneFilter.value = '';
        phones.value = [];
        phoneLogs.value = {};
        activeTab.value = 'console';
    } catch (e) {
        console.error(e);
    } finally {
        clearing.value = false;
    }
};

const switchTab = async (tab: string) => {
    activeTab.value = tab;
    searchFilter.value = '';
    methodFilter.value = '';
    statusFilter.value = null;
    if (tab !== 'console' && !phoneLogs.value[tab]) {
        loading.value = true;
        try {
            await fetchPhoneLogs(tab);
        } finally {
            loading.value = false;
        }
    }
};

// ── Helpers ───────────────────────────────────────────────────────────────────

const toggleExpand = (id: number) => {
    const next = new Set(expandedIds.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    expandedIds.value = next;
};

const copyToClipboard = async (text: string | null, id: string) => {
    if (!text) return;
    try {
        await navigator.clipboard.writeText(text);
        copiedId.value = id;
        setTimeout(() => {
            copiedId.value = null;
        }, 2000);
    } catch (e) {
        console.error('Failed to copy:', e);
    }
};

const isNotOpen = (log: BotLog): boolean => {
    if (!log.response_body) return false;
    try {
        const parsed = JSON.parse(log.response_body);
        return parsed?.status === 'NOT_OPEN';
    } catch {
        return false;
    }
};

const statusBadgeClass = (code: number | null): string => {
    if (!code) return 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400';
    if (code >= 200 && code < 300) return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300';
    if (code === 429) return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
    if (code >= 400) return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300';
    return 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300';
};

const isOtpReceived = (entry: BotLog): boolean =>
    (entry.url?.includes('OTP pre-fetched:') || entry.url?.includes('Portal OTP captured')) ?? false;

const isOtpVerifiedSuccess = (entry: BotLog): boolean =>
    entry.url?.includes('OTP verified successfully') ?? false;

const isSlotNotOpen = (entry: BotLog): boolean =>
    entry.url?.includes('NOT_OPEN') ?? false;

const consoleLabelClass = (label: string | null): string => {
    switch (label) {
        case 'SUCCESS': return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300';
        case 'DONE': return 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300';
        case 'FAIL': return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300';
        case 'WARN': return 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300';
        case 'RETRY': return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
        case 'AUTH': return 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300';
        case 'POLLING': return 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-300';
        case 'IN_PROGRESS':
        case 'WAIT': return 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400';
        default: return 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400';
    }
};

const truncateUrl = (url: string, max = 65): string =>
    url.length > max ? url.slice(0, max) + '…' : url;

const formatTime = (dt: string) =>
    new Date(dt).toLocaleTimeString('en-US', {
        timeZone: 'Asia/Dhaka',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });

const formatTimeMs = (dt: string) => {
    const d = new Date(dt);
    return d.toLocaleTimeString('en-US', {
        timeZone: 'Asia/Dhaka',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });
};

const callSentTime = (log: BotLog): string => {
    if (!log.duration_ms) return formatTimeMs(log.logged_at);
    return formatTimeMs(new Date(new Date(log.logged_at).getTime() - log.duration_ms).toISOString());
};

const formatDuration = (ms: number | null): string => {
    if (ms == null) return '—';
    if (ms >= 1000) return `${(ms / 1000).toFixed(2)}s`;
    return `${ms}ms`;
};

const resolvedIp = (log: BotLog): string => {
    if (log.remote_ip) return log.remote_ip;
    return 'api.ivacbd.com';
};

const colorizeJson = (str: string | null): string => {
    if (!str) return '';
    let formatted: string;
    try {
        formatted = JSON.stringify(JSON.parse(str), null, 2);
    } catch {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    return formatted
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,
            (match) => {
                if (/^"/.test(match)) {
                    if (/:$/.test(match)) return `<span class="text-violet-600 dark:text-violet-400">${match}</span>`;
                    return `<span class="text-emerald-600 dark:text-emerald-400">${match}</span>`;
                }
                if (/true|false/.test(match)) return `<span class="text-amber-500 dark:text-amber-400">${match}</span>`;
                if (/null/.test(match)) return `<span class="text-red-500 dark:text-red-400">${match}</span>`;
                return `<span class="text-blue-600 dark:text-blue-400">${match}</span>`;
            });
};


const isSlotReservedSuccess = (log: BotLog): boolean => {
    if (!log.url?.includes('reserve-slot')) return false;
    if (log.status_code !== 200) return false;
    if (!log.response_body) return false;
    try {
        const parsed = JSON.parse(log.response_body);
        const status = parsed?.status;
        return !!parsed?.reservationId && status !== 'FULL' && status !== 'NOT_OPEN' && status !== 'SLOT_NOT_PREPARED';
    } catch {
        return false;
    }
};

const extractPaymentLink = (log: BotLog): string | null => {
    if (!log.response_body) return null;
    try {
        const parsed = JSON.parse(log.response_body);
        if (parsed?.successFlag === false) return null;
        const data = parsed?.data ?? {};
        const link = data.redirectGatewayURL || data.GatewayPageURL || data.webview_url;
        return typeof link === 'string' && link.length > 0 ? link : null;
    } catch {
        return null;
    }
};

const isPaymentSuccess = (log: BotLog): boolean => {
    const url = log.url ?? '';
    if (!url.includes('payment/ssl/initiate') && !url.includes('payment/dg-epay/initiate')) return false;
    if (log.status_code !== 200 && log.status_code !== 201) return false;
    return extractPaymentLink(log) !== null;
};

const allPhoneLogs = computed(() => {
    const all: BotLog[] = [];
    for (const phone of phones.value) {
        const logs = phoneLogs.value[phone];
        if (logs) all.push(...logs);
    }
    return all;
});

type ReservedEntry = { log: BotLog; phone: string | null; time: string; reservationId: string };
type PaymentEntry = { log: BotLog; phone: string | null; time: string; link: string };

const reservedSlotEntries = computed<ReservedEntry[]>(() =>
    allPhoneLogs.value
        .filter(isSlotReservedSuccess)
        .map(log => {
            let reservationId = '';
            try { reservationId = JSON.parse(log.response_body ?? '')?.reservationId ?? ''; } catch { /* ignore */ }
            return { log, phone: log.account_phone, time: formatTimeMs(log.logged_at), reservationId };
        })
);

const paymentLinkEntries = computed<PaymentEntry[]>(() =>
    allPhoneLogs.value
        .filter(isPaymentSuccess)
        .map(log => ({
            log,
            phone: log.account_phone,
            time: formatTimeMs(log.logged_at),
            link: extractPaymentLink(log)!,
        }))
);

const currentPhoneLogs = computed(() =>
    activeTab.value !== 'console' ? (phoneLogs.value[activeTab.value] ?? []) : []
);

const currentApiLogs = computed(() => currentPhoneLogs.value.filter(l => l.method !== 'LOG'));

const currentPollingLogs = computed(() =>
    currentPhoneLogs.value.filter(l => l.method === 'LOG' && l.label === 'POLLING')
);

const filteredConsoleLogs = computed(() =>
    consoleLogs.value.filter(log => !log.url?.toLowerCase().includes('heartbeat'))
);

const consolePhonesAvailable = computed(() => {
    const set = new Set<string>();
    for (const log of filteredConsoleLogs.value) {
        if (log.account_phone) set.add(log.account_phone);
    }
    return Array.from(set).sort();
});

const systemConsoleLogs = computed(() =>
    filteredConsoleLogs.value.filter(log => !log.account_phone)
);

const phoneConsoleLogs = computed(() => {
    const logs = filteredConsoleLogs.value.filter(log => !!log.account_phone);
    if (!consolePhoneFilter.value) return logs;
    return logs.filter(log => log.account_phone === consolePhoneFilter.value);
});

const applyFilters = (logs: BotLog[]): BotLog[] => {
    let out = logs;

    if (hideHead.value) {
        out = out.filter(log => log.method !== 'HEAD');
    }

    if (methodFilter.value) {
        out = out.filter(log => log.method === methodFilter.value);
    }

    if (statusFilter.value !== null) {
        const target = statusFilter.value;
        if (target === 0) {
            out = out.filter(log => log.status_code == null || !!log.error_type);
        } else {
            out = out.filter(log => log.status_code === target);
        }
    }

    if (!searchFilter.value.trim()) return out;

    const query = searchFilter.value.toLowerCase();
    return out.filter(log =>
        (log.url?.toLowerCase().includes(query) ?? false) ||
        (log.request_body?.toLowerCase().includes(query) ?? false) ||
        (log.response_body?.toLowerCase().includes(query) ?? false) ||
        (log.error_message?.toLowerCase().includes(query) ?? false)
    );
};

const filteredApiLogs = computed(() => applyFilters(currentApiLogs.value));

type StatusCount = { code: number; label: string; count: number };

const buildStatusCounts = (logs: BotLog[]): StatusCount[] => {
    const map = new Map<number, number>();
    for (const log of logs) {
        const key = log.error_type || log.status_code == null ? 0 : log.status_code;
        map.set(key, (map.get(key) ?? 0) + 1);
    }
    return Array.from(map.entries())
        .map(([code, count]) => ({ code, label: code === 0 ? 'ERR' : String(code), count }))
        .sort((a, b) => a.code - b.code);
};

const apiStatusCounts = computed(() => buildStatusCounts(currentApiLogs.value.filter(l => l.method !== 'LOG')));

const toggleStatusFilter = (code: number) => {
    statusFilter.value = statusFilter.value === code ? null : code;
};

// ── Payment Link Alarm (Police Siren) ─────────────────────────────────────────

const alarmActive = ref(false);
const audioUnlocked = ref(false);
const acknowledgedPaymentIds = ref<Set<number>>(new Set());
const originalTitle = typeof document !== 'undefined' ? document.title : '';
let audioCtx: AudioContext | null = null;
let sirenOsc: OscillatorNode | null = null;
let sirenGain: GainNode | null = null;
let sirenTimer: ReturnType<typeof setInterval> | null = null;
let titleTimer: ReturnType<typeof setInterval> | null = null;

const ensureAudioContext = (): AudioContext | null => {
    if (audioCtx && audioCtx.state !== 'closed') {
        if (audioCtx.state === 'suspended') {
            audioCtx.resume().catch(() => { /* ignore */ });
        }
        return audioCtx;
    }
    try {
        const Ctx = (window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext);
        audioCtx = new Ctx();
        return audioCtx;
    } catch {
        return null;
    }
};

// Classic police "wail" — sweep up to ~1500 Hz then back down over ~1 second
const scheduleWail = (ctx: AudioContext, osc: OscillatorNode, gain: GainNode) => {
    const now = ctx.currentTime;
    const low = 650;
    const high = 1550;
    const halfCycle = 0.5; // 1 full wail = 1s → 60 BPM-ish classic cadence

    // Clear any previously scheduled automation
    osc.frequency.cancelScheduledValues(now);
    gain.gain.cancelScheduledValues(now);

    osc.frequency.setValueAtTime(low, now);
    osc.frequency.linearRampToValueAtTime(high, now + halfCycle);
    osc.frequency.linearRampToValueAtTime(low, now + halfCycle * 2);

    gain.gain.setValueAtTime(0.5, now);
};

const startAlarm = () => {
    if (alarmActive.value) return;
    alarmActive.value = true;

    try {
        const ctx = ensureAudioContext();
        if (!ctx) throw new Error('AudioContext unavailable');

        sirenOsc = ctx.createOscillator();
        sirenGain = ctx.createGain();
        sirenOsc.type = 'sawtooth'; // harsh, cuts through — classic siren timbre
        sirenGain.gain.value = 0;
        sirenOsc.connect(sirenGain);
        sirenGain.connect(ctx.destination);
        sirenOsc.start();

        // Kick off immediately, then re-schedule every second for continuous wailing
        scheduleWail(ctx, sirenOsc, sirenGain);
        sirenTimer = setInterval(() => {
            if (sirenOsc && sirenGain && audioCtx) {
                scheduleWail(audioCtx, sirenOsc, sirenGain);
            }
        }, 1000);
    } catch (e) {
        console.error('Alarm audio failed:', e);
    }

    // Flashing title so the user notices from the tab bar
    let flash = false;
    titleTimer = setInterval(() => {
        document.title = flash ? '🚨 PAYMENT LINK — ' + originalTitle : '💳 CLICK TO PAY — ' + originalTitle;
        flash = !flash;
    }, 700);
};

const stopAlarm = () => {
    alarmActive.value = false;

    if (sirenTimer) {
        clearInterval(sirenTimer);
        sirenTimer = null;
    }
    if (titleTimer) {
        clearInterval(titleTimer);
        titleTimer = null;
    }
    if (sirenOsc) {
        try { sirenOsc.stop(); } catch { /* already stopped */ }
        try { sirenOsc.disconnect(); } catch { /* ignore */ }
        sirenOsc = null;
    }
    if (sirenGain) {
        try { sirenGain.disconnect(); } catch { /* ignore */ }
        sirenGain = null;
    }
    document.title = originalTitle;

    // Acknowledge all currently-visible payment links — alarm will only re-fire for NEW ones
    for (const entry of paymentLinkEntries.value) {
        acknowledgedPaymentIds.value.add(entry.log.id);
    }
};

// Pre-unlock audio on the user's first interaction anywhere on the page,
// so the alarm can actually play when it triggers (browsers block autoplay).
const unlockAudio = () => {
    if (audioUnlocked.value) return;
    const ctx = ensureAudioContext();
    if (!ctx) return;
    ctx.resume().catch(() => { /* ignore */ });
    // Play a silent blip to truly unlock on iOS/Safari
    try {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        gain.gain.value = 0;
        osc.connect(gain).connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.01);
    } catch { /* ignore */ }
    audioUnlocked.value = true;
};

// Test button handler (also unlocks audio as a side effect)
const testAlarm = () => {
    unlockAudio();
    if (alarmActive.value) {
        stopAlarm();
    } else {
        startAlarm();
    }
};

watch(paymentLinkEntries, (entries) => {
    const hasNew = entries.some(e => !acknowledgedPaymentIds.value.has(e.log.id));
    if (hasNew && !alarmActive.value) {
        startAlarm();
    }
}, { deep: true, immediate: true });

// ── Auto-scroll on tab switch ─────────────────────────────────────────────────

watch(activeTab, async () => {
    if (!autoScroll.value) return;
    await nextTick(() => {
        if (activeTab.value === 'console') {
            scrollToBottom(systemConsoleScrollEl.value);
            scrollToBottom(phoneConsoleScrollEl.value);
        } else {
            scrollToBottom(apiScrollEl.value);
        }
    });
});

// ── Lifecycle ─────────────────────────────────────────────────────────────────

onMounted(async () => {
    await refresh();
    startTimer();
    document.addEventListener('visibilitychange', onVisibilityChange);

    // Unlock AudioContext on first user interaction so the siren can actually play
    const unlockOnce = () => {
        unlockAudio();
        window.removeEventListener('click', unlockOnce);
        window.removeEventListener('keydown', unlockOnce);
        window.removeEventListener('touchstart', unlockOnce);
    };
    window.addEventListener('click', unlockOnce);
    window.addEventListener('keydown', unlockOnce);
    window.addEventListener('touchstart', unlockOnce);
});

onUnmounted(() => {
    stopTimer();
    document.removeEventListener('visibilitychange', onVisibilityChange);
    if (sirenTimer) clearInterval(sirenTimer);
    if (titleTimer) clearInterval(titleTimer);
    if (sirenOsc) { try { sirenOsc.stop(); sirenOsc.disconnect(); } catch { /* ignore */ } }
    if (sirenGain) { try { sirenGain.disconnect(); } catch { /* ignore */ } }
    if (audioCtx) audioCtx.close().catch(() => { /* ignore */ });
    document.title = originalTitle;
});
</script>

<style scoped>
    :deep(style) {
        content: v-bind('scrollbarStyles');
    }
</style>

<style>
    .scrollbar-modern::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    .scrollbar-modern::-webkit-scrollbar-track {
        background: transparent;
    }
    .scrollbar-modern::-webkit-scrollbar-thumb {
        background: #d4d4d8;
        border-radius: 4px;
        transition: background-color 0.3s ease;
    }
    .scrollbar-modern::-webkit-scrollbar-thumb:hover {
        background: #a1a1a5;
    }
    .dark .scrollbar-modern::-webkit-scrollbar-thumb {
        background: #52525b;
    }
    .dark .scrollbar-modern::-webkit-scrollbar-thumb:hover {
        background: #71717a;
    }
    /* Firefox */
    .scrollbar-modern {
        scrollbar-color: #d4d4d8 transparent;
        scrollbar-width: thin;
    }
    .dark .scrollbar-modern {
        scrollbar-color: #52525b transparent;
    }
</style>

<template>
    <Head :title="`Logs — ${slot.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs" full-width>
        <div class="flex h-full w-full flex-1 flex-col gap-4 p-6">

            <!-- Header -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg bg-blue-100 dark:bg-blue-900/20">
                        <Cloud class="h-5 w-5 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                        <h2 class="text-xl font-bold tracking-tight">{{ slot.name }}</h2>
                        <div class="flex items-center gap-2 text-[11px] text-zinc-500 mt-0.5">
                            <span class="h-1.5 w-1.5 rounded-full"
                                :class="slot.status === 'online' ? 'bg-emerald-500' : 'bg-zinc-400'"></span>
                            <span>{{ slot.status }}</span>
                            <span class="text-zinc-300 dark:text-zinc-600">·</span>
                            <span>{{ slot.worker_state }}</span>
                            <span v-if="slot.ip_address" class="text-zinc-300 dark:text-zinc-600">·</span>
                            <span v-if="slot.ip_address" class="font-mono">{{ slot.ip_address }}</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" :disabled="loading" @click="refresh">
                        <RefreshCw class="h-3.5 w-3.5 mr-1.5" :class="loading ? 'animate-spin' : ''" />
                        Refresh
                    </Button>
                    <Button variant="outline" size="sm"
                        :class="autoScroll ? 'text-blue-600 dark:text-blue-400 border-blue-400 dark:border-blue-600' : ''"
                        @click="autoScroll = !autoScroll">
                        <ArrowDownToLine class="h-3.5 w-3.5 mr-1.5" />
                        Auto-scroll
                    </Button>
                    <Button variant="outline" size="sm"
                        class="cursor-pointer"
                        :class="alarmActive ? 'text-red-600 dark:text-red-400 border-red-400 dark:border-red-600 animate-pulse' : ''"
                        :title="audioUnlocked ? 'Click to test / silence the police siren' : 'Click once to enable sound in this tab'"
                        @click="testAlarm">
                        <component :is="alarmActive ? BellOff : BellRing" class="h-3.5 w-3.5 mr-1.5" />
                        {{ alarmActive ? 'Stop Siren' : 'Test Siren' }}
                    </Button>
                    <Button variant="outline" size="sm" :disabled="clearing" class="border-zinc-200 dark:border-zinc-700 text-red-600 dark:text-red-400 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="clearLogs">
                        <Trash2 class="h-3.5 w-3.5 mr-1.5" />
                        Clear Logs
                    </Button>
                </div>
            </div>

            <!-- Slot Reserved / Payment Banner -->
            <div v-if="reservedSlotEntries.length > 0 || paymentLinkEntries.length > 0"
                class="flex flex-col gap-3 rounded-lg border border-emerald-200/80 dark:border-emerald-900/40 bg-gradient-to-br from-emerald-50/80 to-blue-50/60 dark:from-emerald-950/20 dark:to-blue-950/20 p-4">
                <div v-if="reservedSlotEntries.length > 0" class="flex flex-col gap-1.5">
                    <div class="flex items-center gap-2">
                        <Badge class="bg-emerald-500 text-white border-0 text-[10px] font-bold tracking-wider uppercase flex items-center gap-1">
                            <CheckCircle2 class="h-3 w-3" />
                            Slot Reserved
                        </Badge>
                        <span class="text-[11px] font-semibold text-emerald-700 dark:text-emerald-300">
                            {{ reservedSlotEntries.length }} successful reservation{{ reservedSlotEntries.length === 1 ? '' : 's' }}
                        </span>
                    </div>
                    <ul class="flex flex-col gap-0.5 pl-1">
                        <li v-for="entry in reservedSlotEntries" :key="`res-${entry.log.id}`"
                            class="flex items-center gap-2 text-[11px] font-mono text-emerald-800 dark:text-emerald-200">
                            <span class="text-emerald-500">•</span>
                            <span class="text-zinc-500">{{ entry.time }}</span>
                            <span v-if="entry.phone" class="text-zinc-400">[{{ entry.phone }}]</span>
                            <span class="text-zinc-400">→</span>
                            <span class="font-semibold">{{ entry.reservationId }}</span>
                        </li>
                    </ul>
                </div>
                <div v-if="paymentLinkEntries.length > 0" class="flex flex-col gap-1.5">
                    <div class="flex items-center gap-2">
                        <Badge class="bg-blue-500 text-white border-0 text-[10px] font-bold tracking-wider uppercase flex items-center gap-1">
                            <CreditCard class="h-3 w-3" />
                            Payment Links
                        </Badge>
                        <span class="text-[11px] font-semibold text-blue-700 dark:text-blue-300">
                            {{ paymentLinkEntries.length }} gateway URL{{ paymentLinkEntries.length === 1 ? '' : 's' }}
                        </span>
                        <button
                            v-if="alarmActive"
                            @click="stopAlarm"
                            class="ml-auto flex cursor-pointer items-center gap-1.5 rounded-md bg-red-500 hover:bg-red-600 text-white px-3 py-1 text-[10px] font-bold uppercase tracking-wider animate-pulse shadow-lg shadow-red-500/40"
                        >
                            <BellOff class="h-3.5 w-3.5" />
                            Stop Alarm
                        </button>
                        <span
                            v-else
                            class="ml-auto flex items-center gap-1 text-[10px] text-zinc-500"
                            :title="'Alarm will ring again when a new payment link arrives'"
                        >
                            <BellRing class="h-3 w-3" />
                            Alarm armed
                        </span>
                    </div>
                    <ul class="flex flex-col gap-1 pl-1">
                        <li v-for="entry in paymentLinkEntries" :key="`pay-${entry.log.id}`"
                            class="flex items-center gap-2 text-[11px]">
                            <span class="text-blue-500">•</span>
                            <span class="font-mono text-zinc-500">{{ entry.time }}</span>
                            <span v-if="entry.phone" class="font-mono text-zinc-400">[{{ entry.phone }}]</span>
                            <span class="text-zinc-400">→</span>
                            <a :href="entry.link" target="_blank" rel="noopener noreferrer"
                                class="font-mono text-blue-600 dark:text-blue-400 hover:underline truncate max-w-[45rem]">
                                {{ entry.link }}
                            </a>
                            <button @click="copyToClipboard(entry.link, `paylink-${entry.log.id}`)"
                                class="shrink-0 p-1 rounded hover:bg-blue-100 dark:hover:bg-blue-900/30 transition-colors">
                                <component :is="copiedId === `paylink-${entry.log.id}` ? Check : Copy"
                                    class="h-3 w-3"
                                    :class="copiedId === `paylink-${entry.log.id}` ? 'text-emerald-600' : 'text-blue-500'" />
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex items-center gap-1 border-b border-zinc-200 dark:border-zinc-800">
                <!-- Console tab -->
                <button
                    class="shrink-0 flex items-center gap-1.5 px-3 py-2 text-[11px] font-medium border-b-2 -mb-px transition-colors"
                    :class="activeTab === 'console'
                        ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200'"
                    @click="switchTab('console')"
                >
                    <Terminal class="h-3 w-3" />
                    Console
                    <span v-if="filteredConsoleLogs.length" class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-[9px] font-semibold text-black">
                        {{ filteredConsoleLogs.length }}
                    </span>
                </button>

                <!-- Per-phone tabs -->
                <button
                    v-for="phone in phones"
                    :key="phone"
                    class="shrink-0 flex items-center gap-1 px-3 py-2 text-[11px] font-medium border-b-2 -mb-px transition-colors font-mono"
                    :class="activeTab === phone
                        ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400'
                        : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200'"
                    @click="switchTab(phone)"
                >
                    {{ phone }}
                    <span v-if="phoneLogs[phone]?.length" class="ml-1 rounded-full bg-zinc-100 px-1.5 py-0.5 text-[9px] font-semibold text-black">
                        {{ phoneLogs[phone].length }}
                    </span>
                </button>

                <span v-if="phones.length === 0 && !loading" class="px-3 py-2 text-[11px] text-zinc-400">
                    No account tabs yet — logs will appear once the bot starts.
                </span>
            </div>

            <!-- ── Console Tab ── -->
            <div v-if="activeTab === 'console'">
                <div v-if="filteredConsoleLogs.length === 0 && !loading"
                    class="rounded-lg border border-dashed border-zinc-200 dark:border-zinc-800 p-10 text-center text-sm text-zinc-400">
                    No console log entries yet. Console logs appear here when the bot is running.
                </div>
                <!-- Console pagination -->
                <div v-if="consoleLastPage > 1 || consoleTotalCount > 100" class="mb-3 flex items-center justify-between rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 px-4 py-2">
                    <span class="text-[11px] text-zinc-500">
                        Page <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ consoleCurrentPage }}</span> of
                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ consoleLastPage }}</span>
                        <span class="ml-1 opacity-70">({{ consoleTotalCount.toLocaleString() }} total)</span>
                        <span v-if="consoleCurrentPage > 1" class="ml-2 text-amber-600 dark:text-amber-400 font-medium">· viewing older logs</span>
                    </span>
                    <div class="flex items-center gap-1.5">
                        <button @click="goToConsolePage(1)" :disabled="consoleCurrentPage === 1"
                            class="rounded px-2.5 py-1 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                            Latest
                        </button>
                        <button @click="goToConsolePage(consoleCurrentPage - 1)" :disabled="consoleCurrentPage === 1"
                            class="rounded px-2.5 py-1 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                            Newer
                        </button>
                        <button @click="goToConsolePage(consoleCurrentPage + 1)" :disabled="consoleCurrentPage === consoleLastPage"
                            class="rounded px-2.5 py-1 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                            Older
                        </button>
                        <button @click="goToConsolePage(consoleLastPage)" :disabled="consoleCurrentPage === consoleLastPage"
                            class="rounded px-2.5 py-1 text-[11px] font-medium border border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                            Oldest
                        </button>
                    </div>
                </div>
                <!-- Phone filter pills -->
                <div v-if="consolePhonesAvailable.length > 0" class="mb-3 flex flex-wrap items-center gap-2">
                    <span class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-500 shrink-0">Filter phone</span>
                    <button
                        v-for="phone in consolePhonesAvailable"
                        :key="phone"
                        @click="consolePhoneFilter = consolePhoneFilter === phone ? '' : phone"
                        class="shrink-0 rounded-md px-2.5 py-1 text-[10px] font-mono font-medium border transition-colors"
                        :class="consolePhoneFilter === phone
                            ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                            : 'border-zinc-200 dark:border-zinc-700 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                    >
                        {{ phone }}
                    </button>
                    <button
                        v-if="consolePhoneFilter"
                        @click="consolePhoneFilter = ''"
                        class="rounded-md px-2.5 py-1 text-[10px] font-medium border border-zinc-200 dark:border-zinc-700 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    >
                        ✕ All
                    </button>
                </div>
                <div v-if="filteredConsoleLogs.length > 0" class="flex flex-col xl:flex-row gap-4 items-start">

                    <!-- ── System logs (no phone) ── -->
                    <div class="flex-1 min-w-0 w-full">
                        <div class="mb-2 flex items-center gap-2 text-[11px] font-semibold text-zinc-700 dark:text-zinc-200">
                            <span class="h-1.5 w-1.5 rounded-full bg-zinc-500"></span>
                            System ({{ systemConsoleLogs.length }})
                        </div>
                        <div v-if="systemConsoleLogs.length === 0"
                            class="rounded-lg border border-dashed border-zinc-200 dark:border-zinc-800 p-6 text-center text-[11px] text-zinc-400">
                            No system logs.
                        </div>
                        <div v-else class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 overflow-hidden">
                            <div ref="systemConsoleScrollEl" class="max-h-[70vh] overflow-y-auto font-mono text-[11px] scrollbar-modern">
                                <div
                                    v-for="entry in systemConsoleLogs"
                                    :key="entry.id"
                                    class="flex items-center gap-2 px-3 py-1 border-b border-dotted border-zinc-200/80 dark:border-zinc-700/40 last:border-0"
                                    :class="isSlotNotOpen(entry)
                                        ? 'bg-yellow-50/80 dark:bg-yellow-900/20 hover:bg-yellow-100/60 dark:hover:bg-yellow-900/30'
                                        : (isOtpReceived(entry) || isOtpVerifiedSuccess(entry))
                                        ? 'bg-emerald-50/80 dark:bg-emerald-900/20 hover:bg-emerald-100/60 dark:hover:bg-emerald-900/30'
                                        : 'hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40'"
                                >
                                    <span class="shrink-0 text-[10px] text-zinc-400 w-[4.25rem]">{{ formatTime(entry.logged_at) }}</span>
                                    <span
                                        class="shrink-0 max-w-[70%]"
                                        :class="isSlotNotOpen(entry)
                                            ? 'text-yellow-700 dark:text-yellow-300 font-semibold'
                                            : (isOtpReceived(entry) || isOtpVerifiedSuccess(entry)) ? 'text-emerald-700 dark:text-emerald-300 font-semibold' : 'text-zinc-700 dark:text-zinc-300'"
                                    >{{ entry.url }}</span>
                                    <span class="flex-1 overflow-hidden select-none text-zinc-300 dark:text-zinc-600 leading-none whitespace-nowrap text-[10px]" aria-hidden="true">
                                        ············································································································
                                    </span>
                                    <Badge
                                        class="shrink-0 text-[9px] font-semibold border-0 leading-none py-0.5"
                                        :class="isOtpReceived(entry) ? 'bg-emerald-100 text-black' : consoleLabelClass(entry.label)"
                                    >
                                        {{ entry.label ?? '—' }}
                                    </Badge>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Per-account logs (with phone) ── -->
                    <div class="flex-1 min-w-0 w-full">
                        <div class="mb-2 flex items-center gap-2 text-[11px] font-semibold text-emerald-700 dark:text-emerald-300">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Per-account ({{ phoneConsoleLogs.length }})
                        </div>
                        <div v-if="phoneConsoleLogs.length === 0"
                            class="rounded-lg border border-dashed border-emerald-200 dark:border-emerald-900/40 p-6 text-center text-[11px] text-zinc-400">
                            No per-account logs.
                        </div>
                        <div v-else class="rounded-lg border border-emerald-200/60 dark:border-emerald-900/40 overflow-hidden">
                            <div ref="phoneConsoleScrollEl" class="max-h-[70vh] overflow-y-auto font-mono text-[11px] scrollbar-modern">
                                <div
                                    v-for="entry in phoneConsoleLogs"
                                    :key="entry.id"
                                    class="flex items-center gap-2 px-3 py-1 border-b border-dotted border-zinc-200/80 dark:border-zinc-700/40 last:border-0"
                                    :class="isSlotNotOpen(entry)
                                        ? 'bg-yellow-50/80 dark:bg-yellow-900/20 hover:bg-yellow-100/60 dark:hover:bg-yellow-900/30'
                                        : (isOtpReceived(entry) || isOtpVerifiedSuccess(entry))
                                        ? 'bg-emerald-50/80 dark:bg-emerald-900/20 hover:bg-emerald-100/60 dark:hover:bg-emerald-900/30'
                                        : 'hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40'"
                                >
                                    <span class="shrink-0 text-[10px] text-zinc-400 w-[4.25rem]">{{ formatTime(entry.logged_at) }}</span>
                                    <span class="shrink-0 text-[10px] text-zinc-400">[{{ entry.account_phone }}]</span>
                                    <span
                                        class="shrink-0 max-w-[55%]"
                                        :class="isSlotNotOpen(entry)
                                            ? 'text-yellow-700 dark:text-yellow-300 font-semibold'
                                            : (isOtpReceived(entry) || isOtpVerifiedSuccess(entry)) ? 'text-emerald-700 dark:text-emerald-300 font-semibold' : 'text-zinc-700 dark:text-zinc-300'"
                                    >{{ entry.url }}</span>
                                    <span class="flex-1 overflow-hidden select-none text-zinc-300 dark:text-zinc-600 leading-none whitespace-nowrap text-[10px]" aria-hidden="true">
                                        ············································································································
                                    </span>
                                    <Badge
                                        class="shrink-0 text-[9px] font-semibold border-0 leading-none py-0.5"
                                        :class="isOtpReceived(entry) ? 'bg-emerald-100 text-black' : consoleLabelClass(entry.label)"
                                    >
                                        {{ entry.label ?? '—' }}
                                    </Badge>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ── Per-Phone API Logs Tab ── -->
            <div v-else>
                <!-- Search & Method Filter -->
                <div v-if="activeTab !== 'console'" class="mb-3 flex gap-2">
                    <input
                        v-model="searchFilter"
                        type="text"
                        placeholder="Search logs... (url, request, response, error, limit)"
                        class="flex-1 px-3 py-2 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white text-[11px] placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                    <select
                        v-model="methodFilter"
                        class="px-3 py-2 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white text-[11px] focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="">All Methods</option>
                        <option value="POST">POST</option>
                        <option value="GET">GET</option>
                        <option value="PUT">PUT</option>
                        <option value="DELETE">DELETE</option>
                    </select>
                    <button
                        @click="hideHead = !hideHead"
                        :class="hideHead
                            ? 'border-zinc-300 dark:border-zinc-600 text-zinc-400 dark:text-zinc-500'
                            : 'border-blue-500 bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400'"
                        class="px-3 py-2 rounded-lg border text-[11px] font-mono transition-colors"
                        title="Toggle HEAD requests"
                    >HEAD</button>
                </div>

                <!-- Method Breakdown -->
                <div v-if="activeTab !== 'console' && currentPhoneLogs.length > 0" class="mb-2 flex gap-2 flex-wrap items-center text-[10px]">
                    <span class="text-zinc-400">Methods:</span>
                    <button v-for="method in ['POST', 'GET', 'PUT', 'DELETE']"
                        :key="method"
                        @click="methodFilter = methodFilter === method ? '' : method"
                        :class="methodFilter === method
                            ? 'font-bold text-blue-600 dark:text-blue-400 underline'
                            : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'">
                        {{ method }}: {{ currentApiLogs.filter(l => l.method === method).length }}
                    </button>
                </div>

                <div v-if="loading && !currentPhoneLogs.length"
                    class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40 p-6 text-center text-sm text-zinc-400">
                    Loading…
                </div>

                <div class="flex flex-col xl:flex-row gap-4 items-start w-full">

                <div class="min-w-0 flex-1 w-full">
                    <div v-if="currentApiLogs.length > 0" class="mb-2 flex items-center gap-2 flex-wrap">
                        <div class="flex items-center gap-1.5 text-[11px] font-semibold text-zinc-700 dark:text-zinc-200">
                            <span class="h-1.5 w-1.5 rounded-full bg-zinc-500"></span>
                            API ({{ currentApiLogs.length }})
                        </div>
                        <div class="flex items-center gap-1 flex-wrap">
                            <button
                                v-for="s in apiStatusCounts"
                                :key="`api-${s.code}`"
                                @click="toggleStatusFilter(s.code)"
                                class="text-[9px] px-1.5 py-0.5 rounded-full border transition-colors"
                                :class="statusFilter === s.code
                                    ? 'border-blue-500 bg-blue-500 text-white font-semibold'
                                    : `border-transparent ${statusBadgeClass(s.code === 0 ? null : s.code)} hover:opacity-80`"
                            >
                                {{ s.label }}: {{ s.count }}
                            </button>
                        </div>
                    </div>
                <div v-if="filteredApiLogs.length === 0 && !loading"
                    class="rounded-lg border border-dashed border-zinc-200 dark:border-zinc-800 p-10 text-center text-sm text-zinc-400">
                    {{ searchFilter ? `No logs matching "${searchFilter}"` : methodFilter || statusFilter !== null ? `No logs match the current filters` : `No API logs for ${activeTab} yet.` }}
                </div>
                <div v-else-if="filteredApiLogs.length > 0" class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40 overflow-hidden">
                    <div ref="apiScrollEl" class="max-h-[70vh] overflow-y-auto overflow-x-auto scrollbar-modern">
                    <Table class="min-w-max">
                        <TableHeader class="bg-zinc-50/60 dark:bg-zinc-900/40 border-b border-zinc-200/60 dark:border-zinc-700/60">
                            <TableRow>
                                <TableHead class="pl-3 pr-2 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest border-r w-[3.125rem]">S/N</TableHead>
                                <TableHead class="px-3 py-2 text-[10px] uppercase tracking-widest font-semibold text-zinc-400 w-14 whitespace-nowrap">Method</TableHead>
                                <TableHead class="px-3 py-2 text-[10px] uppercase tracking-widest font-semibold text-zinc-400 w-32 whitespace-nowrap">Sent</TableHead>
                                <TableHead class="px-3 py-2 text-[10px] uppercase tracking-widest font-semibold text-zinc-400 w-40 whitespace-nowrap">IP / Host</TableHead>
                                <TableHead class="px-3 py-2 text-[10px] uppercase tracking-widest font-semibold text-zinc-400 w-96 whitespace-nowrap">URL Path</TableHead>
                                <TableHead class="px-3 py-2 text-[10px] uppercase tracking-widest font-semibold text-zinc-400 w-16 text-center whitespace-nowrap">Status</TableHead>
                                <TableHead class="px-3 py-2 text-[10px] uppercase tracking-widest font-semibold text-zinc-400 w-32 whitespace-nowrap">Received</TableHead>
                                <TableHead class="px-3 py-2 text-[10px] uppercase tracking-widest font-semibold text-zinc-400 w-20 text-right whitespace-nowrap">Duration</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <template v-for="(log, index) in filteredApiLogs" :key="log.id">
                                <TableRow
                                    class="hover:bg-zinc-100/50 dark:hover:bg-zinc-800/50 cursor-pointer transition-colors"
                                    :class="isSlotReservedSuccess(log)
                                        ? 'bg-emerald-100/70 dark:bg-emerald-900/30 hover:bg-emerald-200/70 dark:hover:bg-emerald-900/50'
                                        : isPaymentSuccess(log)
                                        ? 'bg-blue-100/70 dark:bg-blue-900/30 hover:bg-blue-200/70 dark:hover:bg-blue-900/50'
                                        : {
                                            'bg-yellow-50/50 dark:bg-yellow-950/10': isNotOpen(log),
                                            'bg-red-50/50 dark:bg-red-950/10': log.error_type || (log.status_code && log.status_code >= 500),
                                            'bg-amber-50/50 dark:bg-amber-950/10': log.status_code === 429,
                                            'bg-orange-50/50 dark:bg-orange-950/10': log.status_code === 403 || log.status_code === 401,
                                        }"
                                    @click="toggleExpand(log.id)"
                                >
                                    <!-- S/N -->
                                    <TableCell class="pl-3 pr-2 py-1.5 text-center font-mono text-[10px] text-zinc-400 dark:text-zinc-600 tabular-nums border-r border-zinc-200/60 dark:border-zinc-700/60 w-[3.125rem] select-none">
                                        <span class="flex items-center justify-center gap-0.5">
                                            <component :is="expandedIds.has(log.id) ? ChevronDown : ChevronRight" class="h-2.5 w-2.5 shrink-0" />
                                            {{ String(index + 1).padStart(2, '0') }}
                                        </span>
                                    </TableCell>
                                    <!-- Method -->
                                    <TableCell class="px-3 py-1.5 font-mono text-[10px] font-bold text-zinc-500 whitespace-nowrap">
                                        {{ log.method }}
                                    </TableCell>
                                    <!-- Sent -->
                                    <TableCell class="px-3 py-1.5 font-mono text-[10px] text-blue-600 dark:text-blue-400 whitespace-nowrap">
                                        {{ callSentTime(log) }}
                                    </TableCell>
                                    <!-- IP / Host -->
                                    <TableCell class="px-3 py-1.5 font-mono text-[10px] whitespace-nowrap">
                                        <span :class="resolvedIp(log) !== 'api.ivacbd.com'
                                            ? 'text-orange-600 dark:text-orange-400 font-semibold'
                                            : 'text-zinc-400'">
                                            {{ resolvedIp(log) }}
                                        </span>
                                    </TableCell>
                                    <!-- URL (path only) -->
                                    <TableCell class="px-3 py-1.5 font-mono text-[10px] text-zinc-700 dark:text-zinc-300 whitespace-nowrap overflow-hidden text-ellipsis">
                                        <span :title="log.url">{{ truncateUrl(log.url?.replace(/^https?:\/\/[^/]+/, '') || log.url) }}</span>
                                    </TableCell>
                                    <!-- Status -->
                                    <TableCell class="px-3 py-1.5 text-center whitespace-nowrap">
                                        <template v-if="log.error_type">
                                            <Badge class="text-[9px] border-0 bg-red-100 text-black font-semibold">ERR</Badge>
                                        </template>
                                        <template v-else>
                                            <Badge class="text-[9px] border-0" :class="statusBadgeClass(log.status_code)">
                                                {{ log.status_code ?? '—' }}
                                            </Badge>
                                        </template>
                                    </TableCell>
                                    <!-- Received -->
                                    <TableCell class="px-3 py-1.5 font-mono text-[10px] text-emerald-600 dark:text-emerald-400 whitespace-nowrap">
                                        {{ formatTimeMs(log.logged_at) }}
                                    </TableCell>
                                    <!-- Duration -->
                                    <TableCell class="px-3 py-1.5 text-right font-mono text-[10px] whitespace-nowrap"
                                        :class="log.duration_ms && log.duration_ms > 1000 ? 'text-red-500' : log.duration_ms && log.duration_ms > 300 ? 'text-amber-500' : 'text-zinc-500'">
                                        {{ formatDuration(log.duration_ms) }}
                                    </TableCell>
                                </TableRow>
                                <!-- Expanded detail row -->
                                <TableRow v-if="expandedIds.has(log.id)" class="bg-zinc-50 dark:bg-zinc-900/60">
                                    <TableCell colspan="7" class="px-4 py-3">
                                        <div class="flex flex-col gap-4 text-[11px]">
                                            <!-- Error -->
                                            <div v-if="log.error_type || log.error_message">
                                                <p class="font-semibold text-red-600 mb-1 text-[10px] uppercase tracking-widest">Error</p>
                                                <pre class="bg-red-50 dark:bg-red-950/20 rounded-md p-3 text-red-700 dark:text-red-300 overflow-auto max-h-40 whitespace-pre-wrap break-words border border-red-200/60 dark:border-red-900/40 text-[10px]">{{ log.error_type }}: {{ log.error_message }}</pre>
                                            </div>
                                            <!-- Request + Response side by side -->
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                <div v-if="log.request_body != null">
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <p class="font-semibold text-indigo-600 dark:text-indigo-400 text-[10px] uppercase tracking-widest">↑ Request Body</p>
                                                        <button
                                                            @click="copyToClipboard(log.request_body, `req-${log.id}`)"
                                                            class="p-1 hover:bg-indigo-100 dark:hover:bg-indigo-900/30 rounded transition-colors"
                                                            :title="copiedId === `req-${log.id}` ? 'Copied!' : 'Copy to clipboard'"
                                                        >
                                                            <component
                                                                :is="copiedId === `req-${log.id}` ? Check : Copy"
                                                                class="h-3.5 w-3.5"
                                                                :class="copiedId === `req-${log.id}` ? 'text-emerald-600 dark:text-emerald-400' : 'text-indigo-600 dark:text-indigo-400'"
                                                            />
                                                        </button>
                                                    </div>
                                                    <pre class="bg-indigo-50/60 dark:bg-indigo-950/20 border border-indigo-200/60 dark:border-indigo-900/40 rounded-md p-3 overflow-auto max-h-60 whitespace-pre-wrap break-words text-[10px] leading-relaxed" v-html="log.request_body ? colorizeJson(log.request_body) : '(empty)'"></pre>
                                                </div>
                                                <div v-if="log.response_body != null">
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <p class="font-semibold text-teal-600 dark:text-teal-400 text-[10px] uppercase tracking-widest">↓ Response Body</p>
                                                        <button
                                                            @click="copyToClipboard(log.response_body, `res-${log.id}`)"
                                                            class="p-1 hover:bg-teal-100 dark:hover:bg-teal-900/30 rounded transition-colors"
                                                            :title="copiedId === `res-${log.id}` ? 'Copied!' : 'Copy to clipboard'"
                                                        >
                                                            <component
                                                                :is="copiedId === `res-${log.id}` ? Check : Copy"
                                                                class="h-3.5 w-3.5"
                                                                :class="copiedId === `res-${log.id}` ? 'text-emerald-600 dark:text-emerald-400' : 'text-teal-600 dark:text-teal-400'"
                                                            />
                                                        </button>
                                                    </div>
                                                    <pre class="bg-teal-50/60 dark:bg-teal-950/20 border border-teal-200/60 dark:border-teal-900/40 rounded-md p-3 overflow-auto max-h-60 whitespace-pre-wrap break-words text-[10px] leading-relaxed" v-html="log.response_body ? colorizeJson(log.response_body) : '(empty)'"></pre>
                                                </div>
                                                <div v-if="log.request_body == null && log.response_body == null && !log.error_type" class="col-span-2 text-zinc-400">
                                                    No body details.
                                                </div>
                                            </div>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </template>
                        </TableBody>
                    </Table>
                    </div>
                </div>
                </div>

                <!-- ── OTP Polling Sidebar ── -->
                <div class="w-full xl:w-[26.25rem] shrink-0">
                    <div class="mb-2 flex items-center gap-2 text-[11px] font-semibold text-cyan-700 dark:text-cyan-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-cyan-500"></span>
                        OTP Polling ({{ currentPollingLogs.length }})
                    </div>
                    <div v-if="currentPollingLogs.length === 0"
                        class="rounded-lg border border-dashed border-cyan-200 dark:border-cyan-900/40 p-6 text-center text-[11px] text-zinc-400">
                        No OTP polling activity yet.
                    </div>
                    <div v-else class="rounded-lg border border-cyan-200/60 dark:border-cyan-900/40 overflow-hidden">
                        <div class="max-h-[70vh] overflow-y-auto font-mono text-[10px] scrollbar-modern">
                            <table class="w-full">
                                <thead class="bg-cyan-50/60 dark:bg-cyan-950/30 sticky top-0">
                                    <tr class="text-[9px] uppercase tracking-widest text-cyan-700 dark:text-cyan-400">
                                        <th class="px-2 py-1.5 text-left font-semibold w-[4.375rem]">Time</th>
                                        <th class="px-2 py-1.5 text-left font-semibold">Message</th>
                                        <th class="px-2 py-1.5 text-right font-semibold w-[2.5rem]">Hit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="entry in currentPollingLogs" :key="entry.id"
                                        class="border-b border-dotted border-cyan-200/40 dark:border-cyan-900/30 last:border-0 hover:bg-cyan-50/50 dark:hover:bg-cyan-950/20">
                                        <td class="px-2 py-1 text-zinc-400 whitespace-nowrap align-top">{{ formatTime(entry.logged_at) }}</td>
                                        <td class="px-2 py-1 text-zinc-700 dark:text-zinc-300 break-words">{{ entry.url }}</td>
                                        <td class="px-2 py-1 text-right align-top">
                                            <span v-if="entry.url?.includes('OTP=')"
                                                class="inline-block px-1 py-0.5 rounded bg-emerald-100 text-black text-[9px] font-semibold">✓</span>
                                            <span v-else-if="entry.url?.includes('404')"
                                                class="text-zinc-300 dark:text-zinc-600 text-[9px]">·</span>
                                            <span v-else class="text-amber-500 text-[9px]">!</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                </div>

            </div>

        </div>
    </AppLayout>
</template>
