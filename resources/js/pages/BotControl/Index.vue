<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { RefreshCw, Search, Terminal, Copy, Check, Package, Download, ChevronRight, Plus, Trash2, KeyRound, Cloud, ScrollText, Shuffle, Globe, Bot, Wifi, Activity, Users, Link, PlayCircle, StopCircle, CircleX, AlertTriangle } from 'lucide-vue-next';
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
import { useToast } from 'vue-toastification';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';

const props = defineProps<{
    serverTimezone: string;
    allowedStartTime: string;
}>();

const toast = useToast();

// Managers only get accounts.assign, not bot.manage — they're limited to the Account
// Assignment tab and can't touch worker Operations/VPS Setup.
const page = usePage();
const isSuperAdmin = computed(() => page.props.auth.permissions?.['bot.manage'] === true);

interface AgentSlot {
    id: number;
    name: string;
    api_key: string;
    status: 'online' | 'offline';
    worker_state: 'idle' | 'running' | 'stopping';
    pending_command: string | null;
    last_heartbeat_at: string | null;
    ip_address: string | null;
    bot_version: string | null;
    accounts_count: number;
    has_payment_link: boolean;
}

interface Account {
    id: number;
    phone: string;
    tag: string | null;
    status: string;
    is_active: boolean;
    agent_slot_id: number | null;
    agent_slot?: { id: number; name: string } | null;
    user?: { id: number; name: string } | null;
    pdfs_count?: number;
    booking_city?: string | null;
}

// ── Dhaka clock ──
const dhakaTime = ref('');
const countdown = ref('');
const windowStartTime = props.allowedStartTime;

const updateClock = () => {
    const now = new Date();
    const dhaka = new Intl.DateTimeFormat('en-BD', {
        timeZone: 'Asia/Dhaka',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    }).format(now);
    dhakaTime.value = dhaka;

    const [h, m, s] = windowStartTime.split(':').map(Number);
    const dhakaNow = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Dhaka' }));
    const window = new Date(dhakaNow);
    window.setHours(h, m, s, 0);
    const diff = window.getTime() - dhakaNow.getTime();
    if (diff <= 0) {
        countdown.value = 'OPEN';
    } else {
        const totalSec = Math.ceil(diff / 1000);
        const mm = Math.floor(totalSec / 60).toString().padStart(2, '0');
        const ss = (totalSec % 60).toString().padStart(2, '0');
        countdown.value = `${mm}:${ss}`;
    }
};

// ── Operations Tab ──
const slots = ref<AgentSlot[]>([]);
const slotsLoading = ref(true);
const commandLoading = ref<Record<number, boolean>>({});
const commandPending = ref<Record<number, string>>({});
const commandExpectedState = ref<Record<number, string>>({});
const commandSentAt = ref<Record<number, number>>({});
const COMMAND_TIMEOUT_MS = 30_000;

const expectedStateFor = (command: string): string => {
    if (command === 'stop' || command === 'process_restart') return 'idle';
    return 'running'; // start, restart
};

const unassignedCount = computed(() => slots.value.reduce((sum, s) => {
    return sum + (s.accounts_count ?? 0);
}, 0));

const totalWorkers = computed(() => slots.value.length);
const onlineWorkers = computed(() => slots.value.filter(s => s.status === 'online').length);
const runningWorkers = computed(() => slots.value.filter(s => s.worker_state === 'running').length);
const offlineSlotsList = computed(() => slots.value.filter(s => s.status === 'offline'));
const offlineAccountsCount = computed(() => offlineSlotsList.value.reduce((sum, s) => sum + (s.accounts_count ?? 0), 0));

const slotPhones = computed(() => {
    const map: Record<number, { phone: string; tag: string | null }[]> = {};
    for (const account of accounts.value) {
        if (account.agent_slot_id) {
            if (!map[account.agent_slot_id]) map[account.agent_slot_id] = [];
            map[account.agent_slot_id].push({ phone: account.phone, tag: account.tag ?? null });
        }
    }
    return map;
});

// ── Per-phone live status ──
const phoneStatus = ref<Record<string, { url: string; label: string | null; logged_at: string }>>({});
const statusAutoRefresh = ref(false);
let statusRefreshTimer: ReturnType<typeof setInterval> | null = null;
let noisePurgeTimer: ReturnType<typeof setInterval> | null = null;

// ── OTP count per phone ──
const otpCounts = ref<Record<string, number>>({});

async function fetchOtpCount() {
    try {
        const { data } = await axios.get('/api/otps/count');
        otpCounts.value = data;
    } catch {}
}

async function purgeNoise() {
    try {
        await axios.post('/api/db-bot-logs/purge-noise');
    } catch {}
}

async function fetchLatestPerAccount() {
    try {
        const { data } = await axios.get('/api/db-bot-logs/latest-per-account');
        const map: Record<string, { url: string; label: string | null; logged_at: string }> = {};
        for (const row of data) {
            map[`${row.agent_slot_id}:${row.account_phone}`] = row;
        }
        phoneStatus.value = map;
        fetchOtpCount();
    } catch {}
}

function toggleStatusRefresh() {
    statusAutoRefresh.value = !statusAutoRefresh.value;
    if (statusAutoRefresh.value) {
        fetchLatestPerAccount();
        statusRefreshTimer = setInterval(fetchLatestPerAccount, 3000);
        purgeNoise();
        noisePurgeTimer = setInterval(purgeNoise, 60000);
    } else {
        if (statusRefreshTimer) clearInterval(statusRefreshTimer);
        statusRefreshTimer = null;
        if (noisePurgeTimer) clearInterval(noisePurgeTimer);
        noisePurgeTimer = null;
    }
}

function inferPhase(url: string): string {
    if (url.includes('/payment/')) return 'Payment';
    if (url.includes('reserve-slot')) return 'Reserve slot';
    if (url.includes('/otps/')) return 'Verify OTP';
    return 'Sign in';
}

function phaseClass(url: string): string {
    if (url.includes('/payment/')) return 'text-amber-600 dark:text-amber-400';
    if (url.includes('reserve-slot')) return 'text-amber-600 dark:text-amber-400';
    if (url.includes('/otps/')) return 'text-emerald-600 dark:text-emerald-400';
    return 'text-zinc-500 dark:text-zinc-400';
}

function isStale(loggedAt: string): boolean {
    return Date.now() - new Date(loggedAt).getTime() > 60_000;
}

const fetchSlots = async () => {
    try {
        const response = await axios.get('/api/agent-slots');
        slots.value = response.data;

        // Clear pending state when expected worker_state is reached or timed out
        for (const slot of slots.value) {
            const id = slot.id;
            if (!commandPending.value[id]) continue;
            const expected = commandExpectedState.value[id];
            const timedOut = Date.now() - (commandSentAt.value[id] ?? 0) > COMMAND_TIMEOUT_MS;
            if (slot.worker_state === expected || timedOut) {
                delete commandPending.value[id];
                delete commandExpectedState.value[id];
                delete commandSentAt.value[id];
                commandLoading.value[id] = false;
            }
        }
    } catch (e) {
        console.error(e);
    } finally {
        slotsLoading.value = false;
    }
};

// ── Create / delete slots ──
const showAddForm = ref(false);
const newSlotName = ref('');
const addingSlot = ref(false);

const addSlot = async () => {
    if (!newSlotName.value.trim()) return;
    addingSlot.value = true;
    try {
        await axios.post('/api/agent-slots', { name: newSlotName.value.trim() });
        toast.success(`Worker "${newSlotName.value.trim()}" created.`);
        newSlotName.value = '';
        showAddForm.value = false;
        await fetchSlots();
        await fetchAssignSlots();
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? 'Failed to create worker.');
    } finally {
        addingSlot.value = false;
    }
};

const deleteSlot = async (slot: AgentSlot) => {
    if (!confirm(`Delete worker "${slot.name}"? This cannot be undone.`)) return;
    try {
        await axios.delete(`/api/agent-slots/${slot.id}`);
        toast.success(`Worker "${slot.name}" deleted — its accounts were unassigned.`);
        await fetchSlots();
        await fetchAssignSlots();
        await fetchAllAccounts();
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? 'Failed to delete worker.');
    }
};

// ── Delete all offline workers ──
const deleteOfflineDialogOpen = ref(false);
const deletingOffline = ref(false);

const confirmDeleteOffline = async () => {
    deletingOffline.value = true;
    try {
        const { data } = await axios.delete('/api/agent-slots/offline');
        toast.success(
            data.accounts_unassigned > 0
                ? `Deleted ${data.deleted} offline worker(s) — ${data.accounts_unassigned} account(s) unassigned.`
                : `Deleted ${data.deleted} offline worker(s).`
        );
        deleteOfflineDialogOpen.value = false;
        await fetchSlots();
        await fetchAssignSlots();
        await fetchAllAccounts();
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? 'Failed to delete offline workers.');
    } finally {
        deletingOffline.value = false;
    }
};

const forceStopAll = async () => {
    try {
        for (const slot of slots.value) {
            commandLoading.value[slot.id] = true;
            commandPending.value[slot.id] = 'stop';
            commandExpectedState.value[slot.id] = 'idle';
            commandSentAt.value[slot.id] = Date.now();
        }
        await axios.post('/api/agent-slots/command/all', { command: 'process_restart' });
        toast.success(`Process restart sent to ${slots.value.length} workers`);
        startFastPoll();
    } catch (e: any) {
        for (const slot of slots.value) {
            commandLoading.value[slot.id] = false;
            delete commandPending.value[slot.id];
            delete commandExpectedState.value[slot.id];
            delete commandSentAt.value[slot.id];
        }
        toast.error(`Force stop failed: ${e?.response?.data?.message ?? e?.message ?? 'unknown'}`);
    }
};

const sendCommand = async (slotId: number | null, command: string) => {
    try {
        if (slotId === null) {
            // Batch operation: filter to only clickable workers and send individual commands
            let validSlots = slots.value;

            if (command === 'start') {
                validSlots = validSlots.filter(s =>
                    !commandLoading.value[s.id] &&
                    s.status !== 'offline' &&
                    s.worker_state !== 'running' &&
                    s.accounts_count > 0
                );
            } else if (command === 'stop') {
                validSlots = validSlots.filter(s =>
                    !commandLoading.value[s.id] &&
                    s.status !== 'offline' &&
                    s.worker_state !== 'idle'
                );
            } else if (command === 'restart') {
                validSlots = validSlots.filter(s =>
                    !commandLoading.value[s.id] &&
                    s.status !== 'offline' &&
                    s.accounts_count > 0
                );
            }

            if (validSlots.length === 0) {
                toast.info(`No valid workers to send "${command}" command to.`);
                return;
            }

            // Send individual commands to each valid slot
            for (const slot of validSlots) {
                commandLoading.value[slot.id] = true;
                commandPending.value[slot.id] = command;
                commandExpectedState.value[slot.id] = expectedStateFor(command);
                commandSentAt.value[slot.id] = Date.now();

                try {
                    await axios.post(`/api/agent-slots/${slot.id}/command`, { command });
                } catch (e: any) {
                    console.error(`Failed to send ${command} to slot ${slot.id}:`, e);
                    // Clear this slot's pending state on error
                    commandLoading.value[slot.id] = false;
                    delete commandPending.value[slot.id];
                    delete commandExpectedState.value[slot.id];
                    delete commandSentAt.value[slot.id];
                }
            }

            toast.success(`Command "${command}" sent to ${validSlots.length} worker(s).`);
            await fetchSlots();
            startFastPoll();
        } else {
            // Single worker operation
            commandLoading.value[slotId] = true;
            commandPending.value[slotId] = command;
            commandExpectedState.value[slotId] = expectedStateFor(command);
            commandSentAt.value[slotId] = Date.now();
            await axios.post(`/api/agent-slots/${slotId}/command`, { command });
            toast.success(`Command "${command}" sent to worker.`);
            // Don't clear loading here — fetchSlots will clear it once state transitions
            await fetchSlots();
            startFastPoll();
        }
    } catch (e) {
        toast.error('Failed to send command.');
        if (slotId !== null) {
            commandLoading.value[slotId] = false;
            delete commandPending.value[slotId];
            delete commandExpectedState.value[slotId];
            delete commandSentAt.value[slotId];
        }
    }
};

const timeAgo = (dateStr: string | null): string => {
    if (!dateStr) return 'never';
    const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60) return `${diff}s ago`;
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    return `${Math.floor(diff / 3600)}h ago`;
};

// ── Assignment Tab ──
const assignSlots = ref<AgentSlot[]>([]);
const accounts = ref<Account[]>([]);
const paymentLinkPhones = ref<Set<string>>(new Set());
const accountsLoading = ref(false);
const selectedSlotId = ref<number | null | 'unassigned' | 'assigned'>('unassigned');
const selectedAccountIds = ref<number[]>([]);
const accountSearch = ref('');

// Only online workers are offered as filter pills / assignment targets.
const onlineAssignSlots = computed(() => assignSlots.value.filter(s => s.status === 'online'));

// Accounts with no PDF can't clear the upload gate, so they're never assignable here.
const isAssignable = (a: Account): boolean => a.status === 'running' && (a.pdfs_count ?? 0) > 0;

const runningAccounts = computed(() => accounts.value.filter(isAssignable));
const unassignedRunningCount = computed(() => runningAccounts.value.filter(a => !a.agent_slot_id).length);
const assignedRunningCount = computed(() => runningAccounts.value.filter(a => a.agent_slot_id).length);

const fetchAssignSlots = async () => {
    const response = await axios.get('/api/agent-slots');
    assignSlots.value = response.data;
};

const fetchAllAccounts = async () => {
    accountsLoading.value = true;
    try {
        const [accountsRes, phonesRes] = await Promise.all([
            axios.get('/api/accounts', { params: { per_page: 500, status: 'all' } }),
            axios.get('/api/payment-links/phones'),
        ]);
        accounts.value = accountsRes.data.data ?? accountsRes.data;
        paymentLinkPhones.value = new Set(phonesRes.data as string[]);
    } catch (e) {
        console.error(e);
    } finally {
        accountsLoading.value = false;
    }
};

const filteredAccounts = computed(() => {
    let list = runningAccounts.value;
    if (selectedSlotId.value === 'unassigned') {
        list = list.filter(a => !a.agent_slot_id);
    } else if (selectedSlotId.value === 'assigned') {
        list = list.filter(a => a.agent_slot_id);
    } else if (selectedSlotId.value !== null) {
        list = list.filter(a => a.agent_slot_id === selectedSlotId.value);
    }
    if (accountSearch.value) {
        const q = accountSearch.value.toLowerCase();
        list = list.filter(a => a.phone.toLowerCase().includes(q) || (a.tag ?? '').toLowerCase().includes(q));
    }
    if (agentFilter.value) {
        list = list.filter(a => (a.user?.name ?? '') === agentFilter.value);
    }
    if (centerFilter.value) {
        list = centerFilter.value === UNSET_CENTER
            ? list.filter(a => !a.booking_city)
            : list.filter(a => a.booking_city === centerFilter.value);
    }
    return list;
});

// ── Center (IVAC booking city) filter ──
// Sentinel rather than an empty string, which the <select> already uses for "no filter".
const UNSET_CENTER = '__none__';
const centerFilter = ref<string>('');

/**
 * Centers actually present on the running accounts, each with its count.
 *
 * Built from the data rather than IvacBookingCities so the dropdown never offers a center that
 * would return an empty table, and so a city added server-side shows up without a frontend edit.
 */
const centerOptions = computed(() => {
    const counts = new Map<string, number>();
    let unset = 0;
    for (const account of runningAccounts.value) {
        if (!account.booking_city) {
            unset++;
            continue;
        }
        counts.set(account.booking_city, (counts.get(account.booking_city) ?? 0) + 1);
    }
    const options = Array.from(counts.entries())
        .sort((a, b) => a[0].localeCompare(b[0]))
        .map(([value, count]) => ({ value, label: value, count }));

    if (unset > 0) {
        options.push({ value: UNSET_CENTER, label: 'No center', count: unset });
    }
    return options;
});

// Group by agent — same treatment as the Payment Links table.
const groupByAgent = ref(true);
const agentFilter = ref<string | null>(null);

const toggleAgentFilter = (name: string | null | undefined) => {
    if (!name) return;
    agentFilter.value = agentFilter.value === name ? null : name;
};

interface AccountGroup {
    key: string;
    label: string;
    items: { account: Account; idx: number }[];
}

const groupedAccounts = computed<AccountGroup[]>(() => {
    const withIndex = filteredAccounts.value.map((account, idx) => ({ account, idx }));
    if (!groupByAgent.value) {
        return [{ key: '', label: '', items: withIndex }];
    }
    const groups = new Map<string, { account: Account; idx: number }[]>();
    for (const entry of withIndex) {
        const key = entry.account.user?.name || 'Unassigned';
        if (!groups.has(key)) { groups.set(key, []); }
        groups.get(key)!.push(entry);
    }
    return Array.from(groups.entries())
        .sort((a, b) => a[0].localeCompare(b[0]))
        .map(([key, items]) => ({ key, label: key, items }));
});

const toggleAccount = (id: number) => {
    if (selectedAccountIds.value.includes(id)) {
        selectedAccountIds.value = selectedAccountIds.value.filter(x => x !== id);
    } else {
        selectedAccountIds.value.push(id);
    }
};

const toggleAllFiltered = () => {
    const ids = filteredAccounts.value.map(a => a.id);
    const allSelected = ids.every(id => selectedAccountIds.value.includes(id));
    if (allSelected) {
        selectedAccountIds.value = selectedAccountIds.value.filter(id => !ids.includes(id));
    } else {
        ids.forEach(id => {
            if (!selectedAccountIds.value.includes(id)) selectedAccountIds.value.push(id);
        });
    }
};

const bulkAssign = async (targetSlotId: number | null) => {
    if (selectedAccountIds.value.length === 0) {
        toast.warning('No accounts selected.');
        return;
    }

    if (targetSlotId !== null) {
        const selectedAccounts = accounts.value.filter(a => selectedAccountIds.value.includes(a.id));
        const conflicts = selectedAccounts.filter(
            a => a.agent_slot_id !== null && a.agent_slot_id !== targetSlotId,
        );
        if (conflicts.length > 0) {
            const names = [...new Set(conflicts.map(a => a.agent_slot?.name ?? `Slot #${a.agent_slot_id}`))].join(', ');
            const ok = confirm(
                `${conflicts.length} selected account(s) are already assigned to: ${names}.\n\nReassign them to the new worker?`,
            );
            if (!ok) return;
        }
    }

    try {
        await axios.put('/api/accounts/bulk-assign', {
            account_ids: selectedAccountIds.value,
            agent_slot_id: targetSlotId,
        });
        toast.success(`${selectedAccountIds.value.length} account(s) updated.`);
        selectedAccountIds.value = [];
        await fetchAllAccounts();
    } catch (e) {
        toast.error('Failed to assign accounts.');
    }
};

const assigningRandom = ref(false);

const assignRandom = async () => {
    const unassigned = runningAccounts.value.filter(a => !a.agent_slot_id);
    if (unassigned.length === 0) {
        toast.info('No unassigned running accounts to distribute.');
        return;
    }

    const onlineSlots = assignSlots.value.filter(s => s.status === 'online');
    if (onlineSlots.length === 0) {
        toast.warning('No online workers available.');
        return;
    }

    if (!confirm(`Distribute ${unassigned.length} unassigned account(s) round-robin across ${onlineSlots.length} online worker(s)?`)) {
        return;
    }

    // Shuffle accounts so order is randomised before round-robin
    const pool = [...unassigned].sort(() => Math.random() - 0.5);
    const groups: Record<number, number[]> = {};
    for (const slot of onlineSlots) {
        groups[slot.id] = [];
    }
    pool.forEach((account, i) => {
        const slot = onlineSlots[i % onlineSlots.length];
        groups[slot.id].push(account.id);
    });

    assigningRandom.value = true;
    try {
        await Promise.all(
            Object.entries(groups)
                .filter(([, ids]) => ids.length > 0)
                .map(([slotId, ids]) =>
                    axios.put('/api/accounts/bulk-assign', {
                        account_ids: ids,
                        agent_slot_id: Number(slotId),
                    }),
                ),
        );
        toast.success(`Distributed ${pool.length} account(s) across ${onlineSlots.length} worker(s).`);
        selectedAccountIds.value = [];
        await Promise.all([fetchAllAccounts(), fetchAssignSlots(), fetchSlots()]);
    } catch (e) {
        toast.error('Failed to distribute accounts.');
    } finally {
        assigningRandom.value = false;
    }
};

type BotControlTab = 'operations' | 'assignment' | 'setup';

const getInitialTab = (): BotControlTab => {
    if (!isSuperAdmin.value) return 'assignment';
    const t = new URL(window.location.href).searchParams.get('tab');
    return t === 'assignment' || t === 'setup' ? t : 'operations';
};

const activeTab = ref<BotControlTab>(getInitialTab());

const visibleTabs = computed(() => isSuperAdmin.value
    ? [{ id: 'operations', label: 'Operations' }, { id: 'assignment', label: 'Account Assignment' }, { id: 'setup', label: 'VPS Setup' }]
    : [{ id: 'assignment', label: 'Account Assignment' }]);

watch(activeTab, (tab) => {
    if (!isSuperAdmin.value) return;
    const url = new URL(window.location.href);
    if (tab === 'operations') {
        url.searchParams.delete('tab');
    } else {
        url.searchParams.set('tab', tab);
    }
    window.history.replaceState({}, '', url);
});

// ── Setup Tab ──
const jarExists = ref(false);
const buildingJar = ref(false);
const buildOutput = ref('');
const copiedKey = ref<number | null>(null);
const copiedCmd = ref(false);

const installCommand = (slot: AgentSlot) =>
    `curl -fsSL https://ipms.senda.fit/install.sh | sudo bash -s -- ${slot.api_key}`;

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
        toast.success('JAR built — VPS workers can now be installed.');
    } catch (e: any) {
        buildOutput.value = e?.response?.data?.output ?? '';
        toast.error(e?.response?.data?.message ?? 'Build failed. Check output below.');
    } finally {
        buildingJar.value = false;
    }
};

const copyInstallCommand = async (slot: AgentSlot) => {
    try {
        await navigator.clipboard.writeText(installCommand(slot));
        copiedKey.value = slot.id;
        setTimeout(() => { copiedKey.value = null; }, 1500);
    } catch {
        toast.error('Failed to copy to clipboard');
    }
};

// ── Lifecycle ──
let clockInterval: any;
let refreshInterval: any;
let fastPollInterval: any;

const startFastPoll = () => {
    if (fastPollInterval) return; // already polling fast
    fastPollInterval = setInterval(async () => {
        await fetchSlots();
        if (Object.keys(commandPending.value).length === 0) {
            clearInterval(fastPollInterval);
            fastPollInterval = null;
        }
    }, 2000);
};

onMounted(() => {
    updateClock();
    clockInterval = setInterval(updateClock, 1000);
    fetchSlots();
    fetchAssignSlots();
    fetchAllAccounts();
    if (isSuperAdmin.value) {
        fetchJarStatus();
        fetchOtpCount();
    }
    refreshInterval = setInterval(fetchSlots, 10000);
});

onUnmounted(() => {
    clearInterval(clockInterval);
    clearInterval(refreshInterval);
    clearInterval(fastPollInterval);
    if (statusRefreshTimer) clearInterval(statusRefreshTimer);
    if (noisePurgeTimer) clearInterval(noisePurgeTimer);
});

const breadcrumbs = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Bot Control', href: '/bot-control' },
];
</script>

<template>
    <Head title="Bot Control" />

    <AppLayout :breadcrumbs="breadcrumbs" full-width>
        <div class="flex h-full w-full flex-1 flex-col gap-4 p-3 sm:p-4 md:p-6">

            <!-- Header -->
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 shadow-sm shadow-amber-500/30">
                        <Bot class="h-5 w-5 text-white" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Bot Control</h1>
                        <p class="text-muted-foreground mt-0.5 text-sm">Manage distributed VPS workers and account assignments.</p>
                    </div>
                </div>
                <!-- Dhaka Clock -->
                <div class="flex items-stretch gap-2">
                    <div class="flex flex-col justify-center rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 px-3.5 py-2">
                        <span class="text-[10px] uppercase tracking-widest text-zinc-400">Dhaka Time</span>
                        <span class="font-mono text-base font-semibold tabular-nums leading-tight">{{ dhakaTime }}</span>
                    </div>
                    <div class="flex flex-col justify-center rounded-lg border px-3.5 py-2"
                        :class="countdown === 'OPEN' ? 'border-emerald-300 dark:border-emerald-900/60 bg-emerald-50 dark:bg-emerald-950/30' : 'border-amber-300 dark:border-amber-900/60 bg-amber-50 dark:bg-amber-950/20'">
                        <span class="text-[10px] uppercase tracking-widest"
                            :class="countdown === 'OPEN' ? 'text-emerald-500' : 'text-amber-500'">Window In</span>
                        <span class="font-mono text-base font-semibold tabular-nums leading-tight"
                            :class="countdown === 'OPEN' ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">
                            {{ countdown }}
                        </span>
                    </div>
                </div>
            </div>

            <div>
                <!-- Tab nav — plain underline style -->
                <div class="mb-4 flex gap-0 border-b border-zinc-200 dark:border-zinc-800">
                    <button
                        v-for="tab in visibleTabs"
                        :key="tab.id"
                        @click="activeTab = tab.id as 'operations' | 'assignment' | 'setup'"
                        :class="[
                            'cursor-pointer shrink-0 whitespace-nowrap px-4 py-2 text-sm font-medium transition-colors border-b-2 -mb-px',
                            activeTab === tab.id
                                ? 'border-amber-500 text-amber-600 dark:text-amber-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'
                        ]"
                    >{{ tab.label }}</button>
                </div>

                <!-- ── Tab 1: Operations ── -->
                <div v-if="activeTab === 'operations'" class="flex flex-col gap-4">

                    <!-- Workers table — toolbar merged into top -->
                    <div class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm overflow-hidden">

                        <!-- Toolbar (merged into table top) -->
                        <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200/60 dark:border-zinc-700/60 bg-white dark:bg-zinc-950 px-3 py-2">
                            <!-- Stats inline -->
                            <div class="flex items-center gap-3 text-[11px] text-zinc-400 dark:text-zinc-500 font-mono mr-1">
                                <span><span class="font-semibold text-zinc-600 dark:text-zinc-300">{{ totalWorkers }}</span> workers</span>
                                <span class="text-zinc-300 dark:text-zinc-700">·</span>
                                <span><span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ onlineWorkers }}</span> online</span>
                                <span class="text-zinc-300 dark:text-zinc-700">·</span>
                                <span><span class="font-semibold text-blue-600 dark:text-blue-400">{{ runningWorkers }}</span> running</span>
                            </div>
                            <!-- Divider -->
                            <div class="h-4 w-px bg-zinc-200 dark:bg-zinc-700"></div>
                            <!-- Start All -->
                            <button
                                :disabled="unassignedCount === 0"
                                @click="sendCommand(null, 'start')"
                                class="flex items-center gap-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-1.5 text-xs font-medium transition-all active:scale-95 disabled:cursor-not-allowed hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                :class="unassignedCount === 0
                                    ? 'text-zinc-300 dark:text-zinc-600'
                                    : 'cursor-pointer text-emerald-600 dark:text-emerald-400'"
                            >
                                <PlayCircle class="h-3.5 w-3.5 shrink-0" /> Start All
                            </button>
                            <!-- Stop All -->
                            <button
                                :disabled="runningWorkers === 0"
                                @click="sendCommand(null, 'stop')"
                                class="flex items-center gap-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-1.5 text-xs font-medium transition-all active:scale-95 disabled:cursor-not-allowed hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                :class="runningWorkers === 0
                                    ? 'text-zinc-300 dark:text-zinc-600'
                                    : 'cursor-pointer text-red-600 dark:text-red-400'"
                            >
                                <StopCircle class="h-3.5 w-3.5 shrink-0" /> Stop All
                            </button>
                            <!-- Force Stop All -->
                            <button
                                @click="forceStopAll"
                                class="cursor-pointer flex items-center gap-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-1.5 text-xs font-medium text-red-600 dark:text-red-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-all active:scale-95"
                            >
                                <CircleX class="h-3.5 w-3.5 shrink-0" /> Force Stop All
                            </button>
                            <!-- Divider -->
                            <div class="h-4 w-px bg-zinc-200 dark:bg-zinc-700"></div>
                            <!-- Refresh -->
                            <button
                                @click="fetchSlots"
                                class="cursor-pointer flex items-center gap-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-all active:scale-95"
                            >
                                <RefreshCw class="h-3.5 w-3.5" /> Refresh
                            </button>
                            <!-- Live Status -->
                            <button
                                @click="toggleStatusRefresh"
                                class="cursor-pointer flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-xs font-medium transition-all active:scale-95"
                                :class="statusAutoRefresh
                                    ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-500'
                                    : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                            >
                                <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': statusAutoRefresh }" />
                                {{ statusAutoRefresh ? 'Status: ON (3s)' : 'Live Status' }}
                            </button>
                            <!-- Delete Offline Workers -->
                            <button
                                :disabled="offlineSlotsList.length === 0"
                                @click="deleteOfflineDialogOpen = true"
                                class="flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-xs font-medium transition-all active:scale-95 disabled:cursor-not-allowed ml-auto"
                                :class="offlineSlotsList.length === 0
                                    ? 'border-zinc-200 dark:border-zinc-700 text-zinc-300 dark:text-zinc-600'
                                    : 'cursor-pointer border-red-200 dark:border-red-900/50 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30'"
                            >
                                <Trash2 class="h-3.5 w-3.5 shrink-0" /> Delete Offline{{ offlineSlotsList.length > 0 ? ` (${offlineSlotsList.length})` : '' }}
                            </button>
                            <!-- Add Worker -->
                            <button
                                @click="showAddForm = !showAddForm"
                                class="cursor-pointer flex items-center gap-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-1.5 text-xs font-medium bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 hover:bg-zinc-700 dark:hover:bg-zinc-300 transition-all active:scale-95"
                            >
                                <Plus class="h-3.5 w-3.5 shrink-0" /> Add Worker
                            </button>
                        </div>

                        <!-- Inline add form -->
                        <div v-if="showAddForm" class="flex items-center gap-2 border-b border-zinc-200/60 dark:border-zinc-700/60 bg-white dark:bg-zinc-950 px-4 py-3">
                            <KeyRound class="h-4 w-4 text-zinc-400 flex-shrink-0" />
                            <input
                                v-model="newSlotName"
                                type="text"
                                placeholder="Worker name, e.g. VPS-SG-1"
                                class="flex-1 bg-transparent text-sm outline-none placeholder:text-zinc-400"
                                @keydown.enter="addSlot"
                                @keydown.escape="showAddForm = false; newSlotName = ''"
                                autofocus
                            />
                            <Button size="sm" class="cursor-pointer bg-amber-500 hover:bg-amber-600 active:scale-95 text-white transition-all disabled:cursor-not-allowed" :disabled="addingSlot || !newSlotName.trim()" @click="addSlot">
                                {{ addingSlot ? 'Creating…' : 'Create' }}
                            </Button>
                            <Button size="sm" variant="ghost" class="cursor-pointer active:scale-95 transition-all" @click="showAddForm = false; newSlotName = ''">Cancel</Button>
                        </div>

                        <!-- Mobile cards (< sm) -->
                        <div class="sm:hidden divide-y divide-zinc-200/60 dark:divide-zinc-700/60">
                            <template v-if="slotsLoading">
                                <div v-for="i in 3" :key="i" class="px-3 py-3 space-y-2">
                                    <div class="h-4 animate-pulse rounded bg-muted"></div>
                                    <div class="h-3 animate-pulse rounded bg-muted w-2/3"></div>
                                </div>
                            </template>
                            <div v-else-if="slots.length === 0" class="px-4 py-8 text-center text-sm text-zinc-400">
                                No agent slots configured.
                            </div>
                            <div v-else v-for="(slot, index) in slots" :key="slot.id" class="px-3 py-2.5 transition-colors"
                                :class="slot.worker_state === 'running' ? 'bg-emerald-50/70 dark:bg-emerald-950/25' : ''">
                                <!-- Row 1: icon + name + version pill + status dot + state badge -->
                                <div class="flex items-center gap-2">
                                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-emerald-400 to-emerald-600 shadow-sm shadow-emerald-500/20">
                                        <Cloud class="h-3.5 w-3.5 text-white" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <span class="text-[12px] font-semibold">{{ slot.name }}</span>
                                            <span v-if="slot.bot_version" class="bg-sky-500/10 px-2 py-0.5 font-mono text-[9px] text-sky-700 dark:text-sky-300 rounded whitespace-nowrap">
                                                {{ slot.bot_version }}
                                            </span>
                                            <span v-if="slot.has_payment_link" title="Has payment link" class="flex items-center gap-0.5 text-[9px] font-semibold text-emerald-600 dark:text-emerald-400">
                                                <Link class="h-2.5 w-2.5" /> Payment link
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <span class="h-2 w-2 rounded-full"
                                            :class="slot.status === 'online' ? 'bg-emerald-500 animate-pulse' : 'bg-zinc-400'"></span>
                                        <Badge
                                            :class="{
                                                'bg-emerald-100 text-black': slot.worker_state === 'running',
                                                'bg-amber-100 text-black': slot.worker_state === 'stopping',
                                                'bg-zinc-100 text-black': slot.worker_state === 'idle',
                                            }"
                                            class="text-[9px] border-0 font-semibold"
                                        >{{ slot.worker_state }}</Badge>
                                    </div>
                                </div>
                                <!-- Row 2: IP (left) · last seen (right) -->
                                <div class="mt-1 flex items-center justify-between pl-[2.25rem]">
                                    <span class="font-mono text-[10px] text-zinc-400">{{ slot.ip_address ?? '—' }}</span>
                                    <span class="text-[10px] text-zinc-400 tabular-nums">{{ timeAgo(slot.last_heartbeat_at) }}</span>
                                </div>
                                <!-- Row 3: Phones + tags + live phase -->
                                <div v-if="slotPhones[slot.id]?.length" class="mt-1.5 pl-[2.25rem] flex flex-wrap gap-1">
                                    <div v-for="entry in slotPhones[slot.id]" :key="entry.phone" class="flex items-center gap-1">
                                        <span class="font-mono text-[10px] bg-zinc-100 text-black rounded px-1.5 py-0.5 font-semibold">{{ entry.phone }}</span>
                                        <span v-if="entry.tag" class="text-[9px] bg-amber-100 dark:bg-amber-900/30 text-zinc-900 dark:text-zinc-100 rounded px-1.5 py-0.5">{{ entry.tag }}</span>
                                        <span v-if="otpCounts[entry.phone]" class="font-mono font-bold text-[10px] bg-amber-400 text-zinc-900 rounded px-1.5 py-0.5 tabular-nums">{{ otpCounts[entry.phone] }}</span>
                                        <template v-if="phoneStatus[`${slot.id}:${entry.phone}`]">
                                            <span
                                                class="text-[9px] font-semibold"
                                                :class="[phaseClass(phoneStatus[`${slot.id}:${entry.phone}`].url), isStale(phoneStatus[`${slot.id}:${entry.phone}`].logged_at) ? 'opacity-30' : '']"
                                            >{{ inferPhase(phoneStatus[`${slot.id}:${entry.phone}`].url) }}</span>
                                        </template>
                                    </div>
                                </div>
                                <!-- Row 4: Actions -->
                                <div class="mt-2 flex items-center gap-1">
                                    <!-- Start / Stop toggle -->
                                    <button
                                        :disabled="commandLoading[slot.id] || slot.status === 'offline' || (slot.worker_state !== 'running' && slot.accounts_count === 0)"
                                        @click="slot.worker_state === 'running' ? sendCommand(slot.id, 'stop') : sendCommand(slot.id, 'start')"
                                        class="flex items-center gap-1 px-2 py-1 rounded text-[10px] font-medium border border-zinc-200 dark:border-zinc-700 transition-all active:scale-95 disabled:cursor-not-allowed hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                        :class="(commandLoading[slot.id] || slot.status === 'offline' || (slot.worker_state !== 'running' && slot.accounts_count === 0))
                                            ? 'text-zinc-300 dark:text-zinc-600'
                                            : slot.worker_state === 'running'
                                                ? 'cursor-pointer text-red-600 dark:text-red-400'
                                                : 'cursor-pointer text-emerald-600 dark:text-emerald-400'"
                                    >
                                        <RefreshCw v-if="commandPending[slot.id] === 'start' || commandPending[slot.id] === 'stop'" class="h-3 w-3 animate-spin" />
                                        <template v-else>
                                            <StopCircle v-if="slot.worker_state === 'running'" class="h-3 w-3" />
                                            <PlayCircle v-else class="h-3 w-3" />
                                        </template>
                                        {{ slot.worker_state === 'running' ? 'Stop' : 'Start' }}
                                    </button>
                                    <button
                                        :disabled="commandLoading[slot.id] || slot.status === 'offline'"
                                        @click="sendCommand(slot.id, 'process_restart')"
                                        title="Force Stop (systemctl restart)"
                                        class="flex items-center gap-1 px-2 py-1 rounded text-[10px] font-medium border border-zinc-200 dark:border-zinc-700 transition-all active:scale-95 disabled:cursor-not-allowed hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                        :class="(commandLoading[slot.id] || slot.status === 'offline')
                                            ? 'text-zinc-300 dark:text-zinc-600'
                                            : 'cursor-pointer text-red-600 dark:text-red-400'"
                                    >
                                        <RefreshCw v-if="commandPending[slot.id] === 'process_restart'" class="h-3 w-3 animate-spin" />
                                        <CircleX v-else class="h-3 w-3" />
                                        Force
                                    </button>
                                    <a :href="`/slot-logs/${slot.id}`" target="_blank" title="View Logs">
                                        <button class="flex items-center gap-1 px-2 py-1 rounded text-[10px] font-medium cursor-pointer text-amber-600 dark:text-amber-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 transition-all active:scale-95">
                                            <ScrollText class="h-3 w-3" /> Logs
                                        </button>
                                    </a>
                                    <button
                                        :disabled="commandLoading[slot.id]"
                                        @click="deleteSlot(slot)"
                                        title="Delete"
                                        class="flex items-center gap-1 px-2 py-1 rounded text-[10px] font-medium transition-all active:scale-95 disabled:cursor-not-allowed cursor-pointer border border-zinc-200 dark:border-zinc-700 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 hover:text-red-600 dark:hover:text-red-400"
                                    >
                                        <Trash2 class="h-3 w-3" /> Delete
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Desktop table (>= sm) -->
                        <div class="hidden sm:block overflow-x-auto">
                            <Table class="border-b min-w-[60rem]">
                                <TableHeader class="bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm border-b border-zinc-200/60 dark:border-zinc-700/60">
                                    <TableRow>
                                        <TableHead class="pl-3 pr-2 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest border-r w-[3.125rem]">S/N</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest whitespace-nowrap w-[13.75rem]">Name</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Version</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">IP</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Status</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">State</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Accounts</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Last Seen</TableHead>
                                        <TableHead class="pl-2 pr-3 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest border-l">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-if="slotsLoading" v-for="i in 3" :key="i">
                                        <TableCell v-for="j in 9" :key="j" :class="{ 'border-r': j === 1, 'border-l': j === 9 }">
                                            <div class="h-4 animate-pulse rounded bg-muted"></div>
                                        </TableCell>
                                    </TableRow>
                                    <TableRow v-else-if="slots.length === 0" class="h-20 text-center hover:bg-transparent">
                                        <TableCell colspan="9" class="text-muted-foreground text-sm">No agent slots configured.</TableCell>
                                    </TableRow>
                                    <TableRow v-else v-for="(slot, index) in slots" :key="slot.id" class="transition-colors"
                                        :class="slot.worker_state === 'running'
                                            ? 'bg-emerald-50/70 dark:bg-emerald-950/25 hover:bg-emerald-100/70 dark:hover:bg-emerald-900/30'
                                            : 'hover:bg-zinc-100/50 dark:hover:bg-zinc-800/50'">
                                        <TableCell class="pl-3 pr-2 py-1.5 text-center text-[10px] text-zinc-400 dark:text-zinc-600 font-mono tabular-nums border-r">
                                            {{ index + 1 }}
                                        </TableCell>
                                        <TableCell class="px-3 py-1.5">
                                            <div class="flex items-center gap-2">
                                                <div class="p-1.5 rounded bg-emerald-100 dark:bg-emerald-900/20">
                                                    <Cloud class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                                                </div>
                                                <div class="flex flex-col">
                                                    <div class="flex items-center gap-1.5">
                                                        <span class="text-[11px] font-semibold whitespace-nowrap">{{ slot.name }}</span>
                                                        <span v-if="slot.has_payment_link" title="Has payment link" class="flex items-center gap-0.5 text-[9px] font-semibold text-emerald-600 dark:text-emerald-400">
                                                            <Link class="h-2.5 w-2.5" /> PL
                                                        </span>
                                                    </div>
                                                    <span class="text-[10px] text-zinc-400 whitespace-nowrap">ID: {{ slot.id }}</span>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell class="px-3 py-1.5">
                                            <span v-if="slot.bot_version" class="bg-sky-500/10 px-2 py-0.5 font-mono text-sky-700 dark:text-sky-300 rounded text-[10px] whitespace-nowrap">{{ slot.bot_version }}</span>
                                            <span v-else class="text-zinc-400 text-[10px]">—</span>
                                        </TableCell>
                                        <TableCell class="px-3 py-1.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-500">{{ slot.ip_address ?? '—' }}</TableCell>
                                        <TableCell class="px-3 py-1.5">
                                            <div class="flex items-center gap-1.5">
                                                <span class="h-2 w-2 rounded-full"
                                                    :class="slot.status === 'online' ? 'bg-emerald-500 animate-pulse' : 'bg-zinc-400'"></span>
                                                <span class="text-[10px]" :class="slot.status === 'online' ? 'text-emerald-600 dark:text-emerald-400 font-medium' : 'text-zinc-500'">
                                                    {{ slot.status }}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell class="px-3 py-1.5">
                                            <Badge
                                                :class="{
                                                    'bg-emerald-100 text-black': slot.worker_state === 'running',
                                                    'bg-amber-100 text-black': slot.worker_state === 'stopping',
                                                    'bg-zinc-100 text-black': slot.worker_state === 'idle',
                                                }"
                                                class="text-[9px] border-0 font-semibold"
                                            >{{ slot.worker_state }}</Badge>
                                        </TableCell>
                                        <TableCell class="px-3 py-1.5">
                                            <div v-if="slotPhones[slot.id]?.length" class="flex flex-wrap gap-x-3 gap-y-1">
                                                <div v-for="entry in slotPhones[slot.id]" :key="entry.phone" class="flex items-center gap-1.5 whitespace-nowrap">
                                                    <span class="font-mono text-[10px] bg-zinc-100 text-black rounded px-1.5 py-0.5 font-semibold">{{ entry.phone }}</span>
                                                    <span v-if="entry.tag" class="text-[9px] bg-amber-100 dark:bg-amber-900/30 text-zinc-900 dark:text-zinc-100 rounded px-1.5 py-0.5">{{ entry.tag }}</span>
                                                    <span v-if="otpCounts[entry.phone]" class="font-mono font-bold text-xs bg-amber-400 text-zinc-900 rounded px-2 py-0.5 tabular-nums">{{ otpCounts[entry.phone] }}</span>
                                                    <template v-if="phoneStatus[`${slot.id}:${entry.phone}`]">
                                                        <span
                                                            class="text-[9px] font-semibold"
                                                            :class="[phaseClass(phoneStatus[`${slot.id}:${entry.phone}`].url), isStale(phoneStatus[`${slot.id}:${entry.phone}`].logged_at) ? 'opacity-30' : '']"
                                                        >{{ inferPhase(phoneStatus[`${slot.id}:${entry.phone}`].url) }}</span>
                                                    </template>
                                                </div>
                                            </div>
                                            <span v-else class="text-zinc-400 dark:text-zinc-600 text-[10px]">—</span>
                                        </TableCell>
                                        <TableCell class="px-3 py-1.5 text-[10px] text-zinc-500 dark:text-zinc-500">{{ timeAgo(slot.last_heartbeat_at) }}</TableCell>
                                        <TableCell class="pl-2 pr-3 py-1.5 border-l">
                                            <div class="flex items-center justify-center gap-1">
                                                <!-- Start / Stop toggle -->
                                                <button
                                                    :disabled="commandLoading[slot.id] || slot.status === 'offline' || (slot.worker_state !== 'running' && slot.accounts_count === 0)"
                                                    @click="slot.worker_state === 'running' ? sendCommand(slot.id, 'stop') : sendCommand(slot.id, 'start')"
                                                    class="flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium border border-zinc-200 dark:border-zinc-700 transition-all active:scale-95 disabled:cursor-not-allowed hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                                    :class="(commandLoading[slot.id] || slot.status === 'offline' || (slot.worker_state !== 'running' && slot.accounts_count === 0))
                                                        ? 'text-zinc-300 dark:text-zinc-600'
                                                        : slot.worker_state === 'running'
                                                            ? 'cursor-pointer text-red-600 dark:text-red-400'
                                                            : 'cursor-pointer text-emerald-600 dark:text-emerald-400'"
                                                >
                                                    <RefreshCw v-if="commandPending[slot.id] === 'start' || commandPending[slot.id] === 'stop'" class="h-2.5 w-2.5 animate-spin" />
                                                    <template v-else>
                                                        <StopCircle v-if="slot.worker_state === 'running'" class="h-2.5 w-2.5" />
                                                        <PlayCircle v-else class="h-2.5 w-2.5" />
                                                    </template>
                                                    {{ slot.worker_state === 'running' ? 'Stop' : 'Start' }}
                                                </button>
                                                <button
                                                    :disabled="commandLoading[slot.id] || slot.status === 'offline'"
                                                    @click="sendCommand(slot.id, 'process_restart')"
                                                    title="Force Stop (systemctl restart)"
                                                    class="flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium border border-zinc-200 dark:border-zinc-700 transition-all active:scale-95 disabled:cursor-not-allowed hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                                    :class="(commandLoading[slot.id] || slot.status === 'offline')
                                                        ? 'text-zinc-300 dark:text-zinc-600'
                                                        : 'cursor-pointer text-red-600 dark:text-red-400'"
                                                >
                                                    <RefreshCw v-if="commandPending[slot.id] === 'process_restart'" class="h-2.5 w-2.5 animate-spin" />
                                                    <CircleX v-else class="h-2.5 w-2.5" />
                                                    Force
                                                </button>
                                                <a :href="`/slot-logs/${slot.id}`" target="_blank" title="View Logs">
                                                    <button class="flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium cursor-pointer text-amber-600 dark:text-amber-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 transition-all active:scale-95">
                                                        <ScrollText class="h-2.5 w-2.5" /> Logs
                                                    </button>
                                                </a>
                                                <button
                                                    :disabled="commandLoading[slot.id]"
                                                    @click="deleteSlot(slot)"
                                                    title="Delete"
                                                    class="flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium transition-all active:scale-95 disabled:cursor-not-allowed cursor-pointer border border-zinc-200 dark:border-zinc-700 text-zinc-500 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 hover:text-red-600 dark:hover:text-red-400"
                                                >
                                                    <Trash2 class="h-2.5 w-2.5" /> Delete
                                                </button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                </div>

                <!-- ── Tab 3: VPS Setup ── -->
                <div v-if="activeTab === 'setup'" class="flex flex-col gap-6">

                    <!-- JAR build card -->
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sm:gap-4 px-4 sm:px-5 py-3 sm:py-4">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg shadow-sm"
                                    :class="jarExists ? 'bg-gradient-to-br from-emerald-400 to-emerald-600 shadow-emerald-500/25' : 'bg-gradient-to-br from-amber-400 to-orange-500 shadow-amber-500/25'">
                                    <Package class="h-5 w-5 text-white" />
                                </div>
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold">Distributable JAR</div>
                                    <div class="text-[11px] mt-0.5" :class="jarExists ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">
                                        {{ jarExists ? 'Built — ready to deploy to VPS workers' : 'Not built yet — build this before installing on any VPS' }}
                                    </div>
                                </div>
                            </div>
                            <Button
                                size="sm"
                                :disabled="buildingJar"
                                class="cursor-pointer bg-amber-500 hover:bg-amber-600 active:scale-95 text-white border-0 disabled:opacity-50 disabled:cursor-not-allowed w-full sm:w-auto shrink-0 transition-all"
                                @click="buildJar"
                            >
                                <RefreshCw v-if="buildingJar" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                <Package v-else class="mr-1.5 h-3.5 w-3.5" />
                                {{ buildingJar ? 'Building…' : jarExists ? 'Rebuild JAR' : 'Build JAR' }}
                            </Button>
                        </div>
                        <!-- Build output -->
                        <div v-if="buildOutput" class="border-t border-zinc-100 dark:border-zinc-800">
                            <div class="px-4 py-1.5 text-[10px] uppercase tracking-widest text-zinc-400 font-semibold bg-zinc-50 dark:bg-zinc-900">Maven Output</div>
                            <pre class="px-4 py-3 text-[10px] font-mono text-zinc-500 dark:text-zinc-400 overflow-x-auto max-h-48 bg-zinc-950 dark:bg-black whitespace-pre-wrap">{{ buildOutput }}</pre>
                        </div>
                        <div v-if="buildingJar" class="border-t border-zinc-100 dark:border-zinc-800 px-5 py-3 text-[11px] text-zinc-400 flex items-center gap-2">
                            <RefreshCw class="h-3 w-3 animate-spin" />
                            Running mvn clean package — this takes ~30 seconds…
                        </div>
                    </div>

                    <!-- Per-slot install commands -->
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                        <div class="flex items-center gap-2 px-4 py-3 border-b border-zinc-200 dark:border-zinc-800">
                            <Terminal class="h-4 w-4 text-zinc-400" />
                            <span class="text-sm font-semibold">Install Commands</span>
                            <span class="text-[11px] text-zinc-400 ml-1">— SSH into VPS and run as root</span>
                        </div>

                        <div v-if="slots.length === 0" class="px-4 py-8 text-center text-sm text-zinc-400">
                            No agent slots configured. Add slots in the Operations tab first.
                        </div>

                        <div v-else class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <div v-for="slot in slots" :key="slot.id" class="px-4 py-4">
                                <!-- Slot header -->
                                <div class="flex items-center justify-between gap-2 mb-3">
                                    <div class="flex items-center gap-2">
                                        <span class="h-2 w-2 rounded-full flex-shrink-0"
                                            :class="slot.status === 'online' ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-zinc-600'"></span>
                                        <span class="text-[13px] font-semibold">{{ slot.name }}</span>
                                        <span class="text-[10px] text-zinc-400">{{ slot.accounts_count }} accounts</span>
                                    </div>
                                    <button
                                        class="cursor-pointer inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-[11px] font-medium transition-all active:scale-95"
                                        :class="copiedKey === slot.id
                                            ? 'bg-emerald-100 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400'
                                            : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                                        @click="copyInstallCommand(slot)"
                                    >
                                        <Check v-if="copiedKey === slot.id" class="h-3 w-3" />
                                        <Copy v-else class="h-3 w-3" />
                                        {{ copiedKey === slot.id ? 'Copied!' : 'Copy command' }}
                                    </button>
                                </div>
                                <!-- Command box -->
                                <div class="flex items-center gap-2 rounded-md bg-zinc-950 dark:bg-black px-3 py-2.5 font-mono text-[11px] text-emerald-400 overflow-x-auto">
                                    <ChevronRight class="h-3 w-3 text-zinc-600 flex-shrink-0" />
                                    <span class="whitespace-nowrap select-all">{{ installCommand(slot) }}</span>
                                </div>
                                <!-- API key hint -->
                                <div class="mt-1.5 flex items-center gap-1.5 text-[10px] text-zinc-400">
                                    <span class="font-mono bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 rounded">{{ slot.api_key.slice(0, 8) }}…</span>
                                    <span>API key embedded in command</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- What the script does -->
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                        <div class="flex items-center gap-2 px-4 py-3 border-b border-zinc-200 dark:border-zinc-800">
                            <Download class="h-4 w-4 text-zinc-400" />
                            <span class="text-sm font-semibold">What the script does</span>
                        </div>
                        <ol class="px-4 py-3 flex flex-col gap-2 text-[11px] text-zinc-600 dark:text-zinc-400 list-none">
                            <li v-for="(step, i) in [
                                'Verifies it is running as root',
                                'Installs Java 25 via apt (falls back to Java 21 LTS if 25 is unavailable)',
                                'Creates /opt/ipms-bot/ and downloads ivac-booking.jar from https://ipms.senda.fit/api/bot/jar (authenticated by the slot API key)',
                                'Writes /opt/ipms-bot/.env with PORTAL_URL and SLOT_API_KEY',
                                'Installs systemd service (ipms-bot) that starts on boot and auto-restarts on failure',
                                'To update after a JAR rebuild: re-run the same curl command — it replaces the JAR and restarts the service',
                            ]" :key="i" class="flex items-start gap-2">
                                <span class="shrink-0 w-4 h-4 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-500 text-[9px] font-bold flex items-center justify-center mt-0.5">{{ i + 1 }}</span>
                                <span>{{ step }}</span>
                            </li>
                        </ol>
                    </div>

                    <!-- SSH log commands -->
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                        <div class="flex items-center gap-2 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-800">
                            <Terminal class="h-3.5 w-3.5 text-zinc-400" />
                            <span class="text-[10px] uppercase tracking-widest font-semibold text-zinc-400">Useful SSH Commands</span>
                        </div>
                        <div class="grid sm:grid-cols-2 lg:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x divide-zinc-100 dark:divide-zinc-800">
                            <div v-for="cmd in [
                                { label: 'Status',   code: 'systemctl status ipms-bot' },
                                { label: 'Logs',     code: 'journalctl -u ipms-bot -f' },
                                { label: 'Restart',  code: 'systemctl restart ipms-bot' },
                                { label: 'Update',   code: 'curl -fsSL https://ipms.senda.fit/install.sh | sudo bash -s -- <API_KEY>' },
                            ]" :key="cmd.label" class="flex flex-col gap-1 px-4 py-3">
                                <span class="text-[10px] font-semibold uppercase tracking-widest text-zinc-400">{{ cmd.label }}</span>
                                <code class="font-mono text-[10px] text-zinc-600 dark:text-zinc-300">{{ cmd.code }}</code>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ── Tab 2: Account Assignment ── -->
                <div v-if="activeTab === 'assignment'" class="flex flex-col gap-4">

                    <!-- Toolbar -->
                    <div class="flex flex-col gap-2">
                        <!-- Slot filter pills (full-width row) -->
                        <div class="flex items-center gap-1 flex-wrap">
                            <button
                                @click="selectedSlotId = 'unassigned'; selectedAccountIds = []"
                                :class="[
                                    'cursor-pointer px-2.5 py-1 rounded-md text-[11px] font-medium transition-all active:scale-95 border',
                                    selectedSlotId === 'unassigned'
                                        ? 'bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 border-transparent'
                                        : 'bg-white dark:bg-zinc-900 text-zinc-500 border-zinc-200 dark:border-zinc-700 hover:border-zinc-400'
                                ]"
                            >Unassigned <span class="text-[9px] opacity-70">({{ unassignedRunningCount }})</span></button>
                            <button
                                @click="selectedSlotId = 'assigned'; selectedAccountIds = []"
                                :class="[
                                    'cursor-pointer px-2.5 py-1 rounded-md text-[11px] font-medium transition-all active:scale-95 border',
                                    selectedSlotId === 'assigned'
                                        ? 'bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 border-transparent'
                                        : 'bg-white dark:bg-zinc-900 text-zinc-500 border-zinc-200 dark:border-zinc-700 hover:border-zinc-400'
                                ]"
                            >Assigned <span class="text-[9px] opacity-70">({{ assignedRunningCount }})</span></button>
                            <button
                                @click="selectedSlotId = null; selectedAccountIds = []"
                                :class="[
                                    'cursor-pointer px-2.5 py-1 rounded-md text-[11px] font-medium transition-all active:scale-95 border',
                                    selectedSlotId === null
                                        ? 'bg-zinc-900 dark:bg-zinc-100 text-white dark:text-zinc-900 border-transparent'
                                        : 'bg-white dark:bg-zinc-900 text-zinc-500 border-zinc-200 dark:border-zinc-700 hover:border-zinc-400'
                                ]"
                            >All</button>
                            <button
                                v-for="slot in onlineAssignSlots"
                                :key="slot.id"
                                @click="selectedSlotId = slot.id; selectedAccountIds = []"
                                :class="[
                                    'cursor-pointer flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-medium transition-all active:scale-95 border',
                                    selectedSlotId === slot.id
                                        ? 'bg-emerald-600 text-white border-transparent'
                                        : 'bg-white dark:bg-zinc-900 text-zinc-500 border-zinc-200 dark:border-zinc-700 hover:border-emerald-400'
                                ]"
                            >
                                <span class="h-1.5 w-1.5 rounded-full shrink-0"
                                    :class="slot.status === 'online' ? 'bg-emerald-400' : 'bg-zinc-400'"></span>
                                {{ slot.name }}
                                <span class="text-[9px] opacity-70">({{ slot.accounts_count }})</span>
                            </button>
                        </div>

                        <!-- Search + Group by Agent + Assign Random (second row) -->
                        <div class="flex items-center gap-2">
                            <div class="relative flex-1">
                                <Search class="absolute left-2.5 top-2 h-3.5 w-3.5 text-muted-foreground" />
                                <Input v-model="accountSearch" placeholder="Phone or tag…" class="pl-8 h-8 text-[11px] w-full" />
                            </div>
                            <select
                                v-model="centerFilter"
                                @change="selectedAccountIds = []"
                                class="cursor-pointer shrink-0 h-8 rounded-md border px-2 text-[11px] font-medium transition-all bg-white dark:bg-zinc-900"
                                :class="centerFilter
                                    ? 'border-blue-500 text-blue-700 dark:text-blue-400'
                                    : 'border-zinc-200 dark:border-zinc-700 text-zinc-500 hover:border-zinc-400'"
                                title="Filter by IVAC center"
                            >
                                <option value="">All centers</option>
                                <option v-for="option in centerOptions" :key="option.value" :value="option.value">
                                    {{ option.label }} ({{ option.count }})
                                </option>
                            </select>
                            <button
                                @click="groupByAgent = !groupByAgent"
                                class="cursor-pointer shrink-0 flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-[11px] font-medium transition-all active:scale-95"
                                :class="groupByAgent
                                    ? 'border-blue-500 bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400 dark:border-blue-500'
                                    : 'border-zinc-200 dark:border-zinc-700 text-zinc-500 hover:border-zinc-400'"
                            >
                                <Users class="h-3.5 w-3.5 shrink-0" /> Group by Agent
                            </button>
                            <span v-if="agentFilter" class="shrink-0 flex items-center gap-1.5 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 px-2.5 text-[11px] font-semibold text-blue-700 dark:text-blue-300">
                                Agent: {{ agentFilter }}
                                <button type="button" class="text-blue-500 hover:text-blue-700 dark:hover:text-blue-200" title="Clear agent filter" @click="agentFilter = null">
                                    <CircleX class="h-3 w-3" />
                                </button>
                            </span>
                            <Button
                                size="sm"
                                variant="outline"
                                class="cursor-pointer h-8 text-[11px] shrink-0 text-emerald-600 border-emerald-300 hover:text-emerald-700 hover:border-emerald-500 dark:text-emerald-400 dark:border-emerald-700 dark:hover:text-emerald-300 active:scale-95 transition-all disabled:cursor-not-allowed"
                                :disabled="assigningRandom"
                                @click="assignRandom"
                            >
                                <Shuffle v-if="!assigningRandom" class="mr-1.5 h-3.5 w-3.5" />
                                <RefreshCw v-else class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                {{ assigningRandom ? 'Distributing…' : 'Assign Random' }}
                            </Button>
                        </div>
                    </div>

                    <!-- Action bar — shown only when accounts are selected -->
                    <div v-if="selectedAccountIds.length > 0"
                        class="flex items-center gap-2 flex-wrap px-3 py-2 rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/30">
                        <span class="text-[11px] font-medium text-emerald-700 dark:text-emerald-300 w-full sm:w-auto sm:flex-1">
                            {{ selectedAccountIds.length }} account{{ selectedAccountIds.length > 1 ? 's' : '' }} selected
                        </span>
                        <template v-for="slot in onlineAssignSlots" :key="slot.id">
                            <Button size="sm" class="cursor-pointer h-7 text-[10px] bg-emerald-600 hover:bg-emerald-700 active:scale-95 text-white border-0 max-w-full truncate transition-all"
                                @click="bulkAssign(slot.id)">
                                <span class="truncate">→ {{ slot.name }}</span>
                            </Button>
                        </template>
                        <Button size="sm" variant="outline" class="cursor-pointer h-7 text-[10px] text-red-500 border-red-200 dark:border-red-800 active:scale-95 transition-all"
                            @click="bulkAssign(null)">
                            Unassign
                        </Button>
                        <Button size="sm" variant="ghost" class="cursor-pointer h-7 text-[10px] text-zinc-400 active:scale-95 transition-all"
                            @click="selectedAccountIds = []">
                            Cancel
                        </Button>
                    </div>

                    <!-- Table -->
                    <div class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm overflow-hidden">

                        <!-- Mobile cards (< sm) -->
                        <!-- Bounded to the viewport so the list scrolls inside its own box and the
                             page behind it stays put. A max-height rather than flex-1: the layout
                             puts an unsized wrapper between the page and the shell, so h-full does
                             not resolve here and a flex child would collapse to nothing. -->
                        <div class="sm:hidden divide-y divide-zinc-200/60 dark:divide-zinc-700/60 max-h-[calc(100vh-24rem)] min-h-[18rem] overflow-y-auto">
                            <!-- Select all bar -->
                            <div v-if="filteredAccounts.length > 0" class="sticky top-0 z-20 px-3 py-2 flex items-center gap-2 bg-zinc-100/95 dark:bg-zinc-900/95 backdrop-blur-sm border-b border-zinc-200/60 dark:border-zinc-700/60">
                                <input type="checkbox" class="rounded"
                                    :checked="filteredAccounts.length > 0 && filteredAccounts.every(a => selectedAccountIds.includes(a.id))"
                                    @change="toggleAllFiltered" />
                                <span class="text-[11px] text-zinc-400">Select all ({{ filteredAccounts.length }})</span>
                            </div>
                            <template v-if="accountsLoading">
                                <div v-for="i in 5" :key="i" class="px-3 py-3">
                                    <div class="h-4 animate-pulse rounded bg-muted"></div>
                                </div>
                            </template>
                            <div v-else-if="filteredAccounts.length === 0" class="px-4 py-8 text-center text-sm text-zinc-400">
                                {{ agentFilter ? 'No accounts for this agent.' : 'No accounts match the current filter.' }}
                            </div>
                            <template v-else v-for="group in groupedAccounts" :key="group.key || 'all'">
                                <div v-if="groupByAgent" class="px-3 py-1.5 bg-zinc-100/70 dark:bg-zinc-800/40 text-[10px] font-bold uppercase tracking-widest text-zinc-600 dark:text-zinc-300 border-b border-zinc-200 dark:border-zinc-700">
                                    {{ group.label }}
                                    <span class="ml-1.5 text-zinc-400 font-normal normal-case">{{ group.items.length }} account{{ group.items.length === 1 ? '' : 's' }}</span>
                                </div>
                                <div v-for="{ account } in group.items" :key="account.id"
                                    class="px-3 py-2.5 cursor-pointer transition-colors"
                                    :class="selectedAccountIds.includes(account.id) ? 'bg-emerald-50/60 dark:bg-emerald-950/20' : 'hover:bg-zinc-50 dark:hover:bg-zinc-900/60'"
                                    @click="toggleAccount(account.id)">
                                    <!-- Row 1: checkbox + phone + tag + status -->
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" class="rounded shrink-0"
                                            :checked="selectedAccountIds.includes(account.id)"
                                            @click.stop="toggleAccount(account.id)" />
                                        <span class="text-[12px] font-mono font-medium flex-1 min-w-0 truncate">{{ account.phone }}</span>
                                        <span v-if="account.booking_city" class="bg-blue-50 text-black rounded px-1.5 py-0.5 text-[10px] shrink-0 font-semibold">{{ account.booking_city }}</span>
                                        <Link v-if="paymentLinkPhones.has(account.phone)" class="h-3.5 w-3.5 shrink-0 text-emerald-500" title="Has payment link" />
                                        <span v-if="account.tag" class="bg-zinc-100 text-black rounded px-1.5 py-0.5 text-[10px] shrink-0 font-semibold">{{ account.tag }}</span>
                                        <Badge class="text-[9px] border-0 shrink-0" :class="{
                                            'bg-emerald-100 text-black font-semibold': account.status === 'running',
                                            'bg-zinc-100 text-black font-semibold': account.status !== 'running',
                                        }">{{ account.status }}</Badge>
                                    </div>
                                    <!-- Row 2: agent + assigned worker + pdf count -->
                                    <div class="mt-1 pl-6 flex items-center gap-1.5 flex-wrap">
                                        <button v-if="account.user?.name" type="button"
                                            class="text-[10px] text-blue-600 dark:text-blue-400 underline underline-offset-2"
                                            @click.stop="toggleAgentFilter(account.user?.name)"
                                        >{{ account.user.name }}</button>
                                        <span v-if="account.agent_slot"
                                            class="inline-flex items-center gap-1 bg-emerald-100 text-black rounded px-1.5 py-0.5 text-[10px] font-semibold">
                                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-current opacity-60"></span>
                                            {{ account.agent_slot.name }}
                                        </span>
                                        <span v-else class="text-zinc-400 text-[10px]">unassigned</span>
                                        <span :class="(account.pdfs_count ?? 0) > 0
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
                                            : 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400'"
                                            class="rounded px-1.5 py-0.5 text-[10px] font-medium">
                                            {{ account.pdfs_count ?? 0 }} PDF{{ (account.pdfs_count ?? 0) === 1 ? '' : 's' }}
                                        </span>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- Desktop table (>= sm) -->
                        <div class="hidden sm:block overflow-auto max-h-[calc(100vh-24rem)] min-h-[18rem]">
                            <Table class="border-b">
                                <!-- Sticky so the column labels survive scrolling the body. The
                                     background is near-opaque because rows pass underneath it. -->
                                <TableHeader class="sticky top-0 z-20 bg-zinc-100/95 dark:bg-zinc-900/95 backdrop-blur-sm border-b border-zinc-200/60 dark:border-zinc-700/60">
                                    <TableRow>
                                        <TableHead class="pl-3 pr-2 py-2 w-10 border-r">
                                            <input type="checkbox" class="rounded"
                                                :checked="filteredAccounts.length > 0 && filteredAccounts.every(a => selectedAccountIds.includes(a.id))"
                                                @change="toggleAllFiltered" />
                                        </TableHead>
                                        <TableHead class="pl-3 pr-2 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest border-r w-12">#</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Phone</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Center</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Tag</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Agent</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Status</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">PDFs</TableHead>
                                        <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Assigned Worker</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-if="accountsLoading" v-for="i in 5" :key="i">
                                        <TableCell v-for="j in 9" :key="j" :class="{ 'border-r': j <= 2 }">
                                            <div class="h-4 animate-pulse rounded bg-muted"></div>
                                        </TableCell>
                                    </TableRow>
                                    <TableRow v-else-if="filteredAccounts.length === 0" class="h-24">
                                        <TableCell colspan="9" class="text-center text-muted-foreground text-sm">
                                            {{ agentFilter ? 'No accounts for this agent.' : 'No accounts match the current filter.' }}
                                        </TableCell>
                                    </TableRow>
                                    <template v-else v-for="group in groupedAccounts" :key="group.key || 'all'">
                                        <TableRow v-if="groupByAgent" class="bg-zinc-100/70 dark:bg-zinc-800/40 hover:bg-zinc-100/70 dark:hover:bg-zinc-800/40">
                                            <TableCell colspan="9" class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-widest text-zinc-600 dark:text-zinc-300 border-b border-zinc-200 dark:border-zinc-700">
                                                {{ group.label }}
                                                <span class="ml-1.5 text-zinc-400 font-normal normal-case">{{ group.items.length }} account{{ group.items.length === 1 ? '' : 's' }}</span>
                                            </TableCell>
                                        </TableRow>
                                        <TableRow
                                            v-for="{ account, idx } in group.items"
                                            :key="account.id"
                                            class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-900/60 transition-colors"
                                            :class="selectedAccountIds.includes(account.id) ? 'bg-emerald-50/60 dark:bg-emerald-950/20' : ''"
                                            @click="toggleAccount(account.id)"
                                        >
                                            <TableCell class="pl-3 pr-2 py-1.5 border-r" @click.stop="toggleAccount(account.id)">
                                                <input type="checkbox" class="rounded"
                                                    :checked="selectedAccountIds.includes(account.id)"
                                                    @click.stop="toggleAccount(account.id)" />
                                            </TableCell>
                                            <TableCell class="pl-3 pr-2 py-1.5 text-center text-[10px] text-zinc-400 font-mono tabular-nums border-r">
                                                {{ idx + 1 }}
                                            </TableCell>
                                            <TableCell class="px-3 py-1.5">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="text-[11px] font-mono">{{ account.phone }}</span>
                                                    <Link v-if="paymentLinkPhones.has(account.phone)" class="h-3 w-3 shrink-0 text-emerald-500" title="Has payment link" />
                                                </div>
                                            </TableCell>
                                            <TableCell class="px-3 py-1.5">
                                                <span v-if="account.booking_city" class="bg-blue-50 text-black rounded px-1.5 py-0.5 text-[10px] font-semibold">{{ account.booking_city }}</span>
                                                <span v-else class="text-zinc-400 text-[10px]">—</span>
                                            </TableCell>
                                            <TableCell class="px-3 py-1.5">
                                                <span v-if="account.tag" class="bg-zinc-100 text-black rounded px-1.5 py-0.5 text-[10px] font-semibold">{{ account.tag }}</span>
                                                <span v-else class="text-zinc-400 text-[10px]">—</span>
                                            </TableCell>
                                            <TableCell class="px-3 py-1.5" @click.stop>
                                                <button v-if="account.user?.name" type="button"
                                                    class="text-[11px] text-blue-600 dark:text-blue-400 underline underline-offset-2 hover:text-blue-800 dark:hover:text-blue-300"
                                                    :title="agentFilter === account.user.name ? 'Clear agent filter' : `Show only ${account.user.name}`"
                                                    @click="toggleAgentFilter(account.user?.name)"
                                                >{{ account.user.name }}</button>
                                                <span v-else class="text-zinc-400 text-[10px]">—</span>
                                            </TableCell>
                                            <TableCell class="px-3 py-1.5">
                                                <Badge class="text-[9px] border-0" :class="{
                                                    'bg-emerald-100 text-black font-semibold': account.status === 'running',
                                                    'bg-zinc-100 text-black font-semibold': account.status !== 'running',
                                                }">{{ account.status }}</Badge>
                                            </TableCell>
                                            <TableCell class="px-3 py-1.5">
                                                <span v-if="(account.pdfs_count ?? 0) > 0" class="bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300 rounded px-1.5 py-0.5 text-[10px] font-medium">
                                                    {{ account.pdfs_count }}
                                                </span>
                                                <span v-else class="bg-red-100 text-black rounded px-1.5 py-0.5 text-[10px] font-semibold">0</span>
                                            </TableCell>
                                            <TableCell class="px-3 py-1.5">
                                                <span v-if="account.agent_slot"
                                                    class="inline-flex items-center gap-1 bg-emerald-100 text-black rounded px-1.5 py-0.5 text-[10px] whitespace-nowrap font-semibold">
                                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-current opacity-60"></span>
                                                    {{ account.agent_slot.name }}
                                                </span>
                                                <span v-else class="text-zinc-400 text-[10px]">—</span>
                                            </TableCell>
                                        </TableRow>
                                    </template>
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Confirm: delete all offline workers -->
        <Dialog v-model:open="deleteOfflineDialogOpen">
            <DialogContent class="sm:max-w-[28.75rem] p-0 overflow-hidden !bg-white dark:!bg-zinc-900">
                <DialogHeader class="px-5 pt-5 pb-3 border-b border-zinc-200 dark:border-zinc-800">
                    <div class="flex items-center gap-2.5">
                        <div class="h-9 w-9 rounded-full bg-red-100 dark:bg-red-950/40 flex items-center justify-center">
                            <Trash2 class="h-4 w-4 text-red-600 dark:text-red-400" />
                        </div>
                        <div class="flex flex-col">
                            <DialogTitle class="text-[14px] font-semibold">
                                Delete {{ offlineSlotsList.length }} offline worker{{ offlineSlotsList.length === 1 ? '' : 's' }}?
                            </DialogTitle>
                            <DialogDescription class="text-[11px] text-zinc-500 mt-0.5">
                                This cannot be undone.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>
                <div class="px-5 py-4 space-y-3">
                    <ul class="max-h-40 overflow-y-auto rounded-md border border-zinc-200 dark:border-zinc-800 divide-y divide-zinc-200 dark:divide-zinc-800">
                        <li v-for="slot in offlineSlotsList" :key="slot.id" class="flex items-center justify-between px-3 py-1.5 text-[12px]">
                            <span class="text-zinc-700 dark:text-zinc-300">{{ slot.name }}</span>
                            <span v-if="slot.accounts_count > 0" class="text-[10px] text-amber-600 dark:text-amber-400">
                                {{ slot.accounts_count }} account{{ slot.accounts_count === 1 ? '' : 's' }}
                            </span>
                        </li>
                    </ul>
                    <div v-if="offlineAccountsCount > 0" class="rounded-md border border-amber-200 dark:border-amber-900/40 bg-amber-50 dark:bg-amber-950/20 p-3 text-[12px] text-amber-800 dark:text-amber-300 leading-relaxed flex items-start gap-2">
                        <AlertTriangle class="h-3.5 w-3.5 mt-0.5 shrink-0" />
                        <span><span class="font-semibold">{{ offlineAccountsCount }}</span> account{{ offlineAccountsCount === 1 ? '' : 's' }} currently assigned to these workers will be unassigned.</span>
                    </div>
                </div>
                <DialogFooter class="px-5 py-3 border-t border-zinc-200 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-900/40 flex gap-2">
                    <Button variant="outline" size="sm" class="cursor-pointer" :disabled="deletingOffline" @click="deleteOfflineDialogOpen = false">Cancel</Button>
                    <Button size="sm" variant="outline" class="cursor-pointer border-red-300 text-red-600 hover:bg-red-50 dark:border-red-900/50 dark:text-red-400 dark:hover:bg-red-950/30" :disabled="deletingOffline" @click="confirmDeleteOffline">
                        <Trash2 class="h-3.5 w-3.5 mr-1.5" />
                        {{ deletingOffline ? 'Deleting…' : 'Delete Offline Workers' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
