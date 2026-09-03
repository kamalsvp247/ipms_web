<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { ArrowDown, ArrowUp, ArrowUpDown, Mail, MessageSquare, RefreshCw, Send, Trash2, KeyRound } from 'lucide-vue-next';
import { ref, onMounted, onBeforeUnmount, computed } from 'vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface OtpRow {
    id: number;
    phone: string;
    otp_code: string | null;
    message: string | null;
    is_ivacbd: boolean;
    source_gmail_id: string | null;
    to_address: string | null;
    fetched_at: string;
    server_time: string | null;
}

defineProps<{ accountPhones: string[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'OTPs', href: '/otps' },
];

function dhakaToday(): string {
    const fmt = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Dhaka',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
    return fmt.format(new Date());
}

const docsMethod = ref<'get' | 'post'>('get');

const filterDate = ref(dhakaToday());
const filterPhone = ref('');
const filterIvacbd = ref<'' | '0' | '1'>('');
const filterChannel = ref<'' | 'sms' | 'email'>('');
const perPage = ref(50);
const currentPage = ref(1);
const lastPage = ref(1);
const totalEntries = ref(0);

const entries = ref<OtpRow[]>([]);
const loading = ref(false);
const selectedIds = ref<Set<number>>(new Set());
const deleting = ref(false);
const cleaningUp = ref(false);

type SortField = 'fetched_at' | 'phone';
const sortBy = ref<SortField>('fetched_at');
const sortDir = ref<'asc' | 'desc'>('desc');

function toggleSort(field: SortField) {
    if (sortBy.value === field) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortBy.value = field;
        sortDir.value = field === 'phone' ? 'asc' : 'desc';
    }
    loadEntries(true);
}

const allSelected = computed(() => entries.value.length > 0 && entries.value.every((e) => selectedIds.value.has(e.id)));
const someSelected = computed(() => entries.value.some((e) => selectedIds.value.has(e.id)) && !allSelected.value);

function toggleAll(checked: boolean) {
    if (checked) {
        entries.value.forEach((e) => selectedIds.value.add(e.id));
    } else {
        entries.value.forEach((e) => selectedIds.value.delete(e.id));
    }
    selectedIds.value = new Set(selectedIds.value);
}

function toggleRow(id: number, checked: boolean) {
    if (checked) selectedIds.value.add(id);
    else selectedIds.value.delete(id);
    selectedIds.value = new Set(selectedIds.value);
}

async function deleteOne(id: number) {
    if (!confirm('Delete this OTP record?')) return;
    try {
        await axios.delete('/api/otps', { data: { ids: [id] } });
        selectedIds.value.delete(id);
        selectedIds.value = new Set(selectedIds.value);
        await loadEntries(false);
    } catch {
        alert('Failed to delete.');
    }
}

async function cleanUpNonIvacbd() {
    if (!confirm('Delete all non-IVACBD rows? This cannot be undone.')) return;
    cleaningUp.value = true;
    try {
        await axios.delete('/api/otps/non-ivacbd');
        selectedIds.value = new Set();
        await loadEntries(false);
    } catch {
        alert('Failed to clean up.');
    } finally {
        cleaningUp.value = false;
    }
}

async function deleteSelected() {
    if (selectedIds.value.size === 0) return;
    if (!confirm(`Delete ${selectedIds.value.size} OTP record${selectedIds.value.size === 1 ? '' : 's'}?`)) return;
    deleting.value = true;
    try {
        await axios.delete('/api/otps', { data: { ids: Array.from(selectedIds.value) } });
        selectedIds.value = new Set();
        await loadEntries(false);
    } catch {
        alert('Failed to delete.');
    } finally {
        deleting.value = false;
    }
}

// Insert form state
const insertMode = ref<'message' | 'direct'>('message');
const insertPhone = ref('');
const insertMessage = ref('');
const insertOtp = ref('');
const inserting = ref(false);
const insertError = ref<string | null>(null);
const insertSuccess = ref<string | null>(null);

async function loadEntries(resetPage = true) {
    if (resetPage) currentPage.value = 1;
    loading.value = true;
    try {
        const params: Record<string, string | number> = {
            date: filterDate.value,
            per_page: perPage.value,
            page: currentPage.value,
        };
        if (filterPhone.value.trim()) params.phone = filterPhone.value.trim();
        if (filterIvacbd.value !== '') params.is_ivacbd = filterIvacbd.value;
        if (filterChannel.value !== '') params.channel = filterChannel.value;
        params.sort_by = sortBy.value;
        params.sort_dir = sortDir.value;
        const { data } = await axios.get('/api/otps', { params });
        entries.value = data.data ?? [];
        totalEntries.value = data.total ?? 0;
        lastPage.value = data.last_page ?? 1;
        currentPage.value = data.current_page ?? 1;
    } catch {
        entries.value = [];
        totalEntries.value = 0;
        lastPage.value = 1;
    } finally {
        loading.value = false;
    }
}

function goToPage(page: number) {
    if (page < 1 || page > lastPage.value || page === currentPage.value) return;
    currentPage.value = page;
    loadEntries(false);
}

function formatTime(dt: string): string {
    return new Date(dt).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'Asia/Dhaka' });
}

function formatServerTime(st: string): string {
    return st;
}

const ivacbdCount = computed(() => entries.value.filter((e) => e.is_ivacbd).length);

async function submitInsert() {
    insertError.value = null;
    insertSuccess.value = null;

    const phone = insertPhone.value.trim();
    if (!phone) {
        insertError.value = 'Phone is required.';
        return;
    }
    if (insertMode.value === 'message' && !insertMessage.value.trim()) {
        insertError.value = 'Message is required.';
        return;
    }
    if (insertMode.value === 'direct' && !/^\d{4,8}$/.test(insertOtp.value.trim())) {
        insertError.value = 'OTP must be 4–8 digits.';
        return;
    }

    inserting.value = true;
    try {
        const payload: Record<string, string> = { phone, mode: insertMode.value };
        if (insertMode.value === 'message') {
            payload.message = insertMessage.value;
        } else {
            payload.otp_code = insertOtp.value.trim();
        }
        const { data } = await axios.post('/api/otps', payload);
        insertSuccess.value = data.otp_code
            ? `Inserted OTP ${data.otp_code} for ${data.phone}.`
            : `Inserted message for ${data.phone} (no OTP extracted).`;
        insertPhone.value = '';
        insertMessage.value = '';
        insertOtp.value = '';
        await loadEntries(true);
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
        const errs = err.response?.data?.errors;
        if (errs) {
            insertError.value = Object.values(errs).flat().join(' ');
        } else {
            insertError.value = err.response?.data?.message ?? 'Failed to insert.';
        }
    } finally {
        inserting.value = false;
    }
}

let pollTimer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    loadEntries();
    pollTimer = setInterval(() => {
        if (!loading.value && !deleting.value && !inserting.value) {
            loadEntries(false);
        }
    }, 3000);
});

onBeforeUnmount(() => {
    if (pollTimer) clearInterval(pollTimer);
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :full-width="true">
        <Head title="OTPs" />

        <datalist id="account-phones">
            <option v-for="p in accountPhones" :key="p" :value="p" />
        </datalist>

        <div class="flex h-full w-full flex-1 flex-col gap-4 p-4 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 shadow-sm shadow-amber-500/30">
                        <KeyRound class="h-5 w-5 text-white" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">OTPs</h1>
                        <p class="text-muted-foreground mt-0.5 text-sm">Forwarded SMS OTPs, filtered by date</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="selectedIds.size > 0"
                        size="sm"
                        variant="outline"
                        @click="deleteSelected"
                        :disabled="deleting"
                        class="gap-1.5 border-zinc-200 dark:border-zinc-700 text-red-600 dark:text-red-400 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                        {{ deleting ? 'Deleting…' : `Delete (${selectedIds.size})` }}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="cleanUpNonIvacbd"
                        :disabled="cleaningUp"
                        class="gap-1.5 border-zinc-200 dark:border-zinc-700 text-orange-600 dark:text-orange-400 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                        {{ cleaningUp ? 'Cleaning…' : 'Clean Up' }}
                    </Button>
                    <Button size="sm" variant="outline" @click="loadEntries(false)" :disabled="loading" class="gap-1.5">
                        <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': loading }" />
                        {{ loading ? 'Loading…' : 'Refresh' }}
                    </Button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-[1fr_360px]">
                <!-- LEFT: filters + table -->
                <div class="flex flex-col gap-4 min-w-0">
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950">
                        <div class="grid grid-cols-2 items-end gap-3 px-4 py-3 sm:flex sm:flex-wrap">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Date (BDT)</label>
                                <input
                                    v-model="filterDate"
                                    type="date"
                                    @change="loadEntries(true)"
                                    class="h-9 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 py-1 text-xs text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Phone</label>
                                <input
                                    v-model="filterPhone"
                                    list="account-phones"
                                    placeholder="01..."
                                    @keyup.enter="loadEntries(true)"
                                    @blur="loadEntries(true)"
                                    @change="loadEntries(true)"
                                    class="h-9 w-full rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 py-1 text-xs text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 sm:w-40"
                                />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Source</label>
                                <select
                                    v-model="filterIvacbd"
                                    @change="loadEntries(true)"
                                    class="h-9 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 py-1 text-xs text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                >
                                    <option value="">All</option>
                                    <option value="1">IVACBD only</option>
                                    <option value="0">Non-IVACBD</option>
                                </select>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Channel</label>
                                <select
                                    v-model="filterChannel"
                                    @change="loadEntries(true)"
                                    class="h-9 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 py-1 text-xs text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                >
                                    <option value="">All</option>
                                    <option value="sms">SMS only</option>
                                    <option value="email">Email only</option>
                                </select>
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
                                </select>
                            </div>
                            <div class="col-span-2 text-xs text-zinc-500 dark:text-zinc-400 sm:col-span-1 sm:ml-auto">
                                <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ totalEntries }}</span> total ·
                                <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ ivacbdCount }}</span> IVACBD on page
                            </div>
                        </div>
                    </div>

                    <template v-if="entries.length > 0">
                        <!-- Mobile card list -->
                        <div class="flex flex-col gap-2 md:hidden">
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 px-3 py-2 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                <input
                                    type="checkbox"
                                    :checked="allSelected"
                                    :indeterminate.prop="someSelected"
                                    @change="toggleAll(($event.target as HTMLInputElement).checked)"
                                    class="h-4 w-4 cursor-pointer rounded border-zinc-300 text-emerald-600 focus:ring-emerald-500 dark:border-zinc-600"
                                />
                                Select all on page
                            </label>
                            <div
                                v-for="entry in entries"
                                :key="entry.id"
                                class="rounded-lg border border-zinc-200 dark:border-zinc-800 p-3"
                                :class="selectedIds.has(entry.id) ? 'bg-blue-50 dark:bg-blue-950/30' : 'bg-white dark:bg-zinc-950'"
                            >
                                <div class="flex items-start gap-2.5">
                                    <input
                                        type="checkbox"
                                        :checked="selectedIds.has(entry.id)"
                                        @change="toggleRow(entry.id, ($event.target as HTMLInputElement).checked)"
                                        class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-zinc-300 text-emerald-600 focus:ring-emerald-500 dark:border-zinc-600"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-mono text-xs font-semibold text-zinc-800 dark:text-zinc-200">{{ entry.phone }}</span>
                                            <span class="font-mono text-[10px] tabular-nums text-zinc-500 dark:text-zinc-400">{{ formatTime(entry.fetched_at) }}</span>
                                        </div>
                                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                            <span v-if="entry.otp_code" class="font-mono text-base font-bold tracking-wider text-emerald-700 dark:text-emerald-400">{{ entry.otp_code }}</span>
                                            <span
                                                v-if="entry.source_gmail_id"
                                                class="inline-flex items-center gap-1 rounded-md bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-black"
                                            ><Mail class="h-2.5 w-2.5" />Email</span>
                                            <span
                                                v-else
                                                class="inline-flex items-center gap-1 rounded-md bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold text-black"
                                            ><MessageSquare class="h-2.5 w-2.5" />SMS</span>
                                            <span v-if="entry.is_ivacbd" class="inline-flex rounded bg-emerald-100 px-1.5 py-0.5 text-[9px] font-semibold text-black">IVACBD</span>
                                            <span v-else class="inline-flex rounded bg-zinc-100 px-1.5 py-0.5 text-[9px] font-semibold text-black">OTHER</span>
                                        </div>
                                        <div v-if="entry.to_address" class="mt-1 break-all font-mono text-[10px] text-blue-600 dark:text-blue-400">{{ entry.to_address }}</div>
                                        <p v-if="entry.message" class="mt-1 break-words text-[11px] text-zinc-600 dark:text-zinc-400">{{ entry.message }}</p>
                                        <div v-if="entry.server_time" class="mt-1 font-mono text-[10px] tabular-nums text-blue-600 dark:text-blue-400">Server: {{ formatServerTime(entry.server_time) }}</div>
                                    </div>
                                    <button
                                        type="button"
                                        @click="deleteOne(entry.id)"
                                        title="Delete this OTP"
                                        class="shrink-0 cursor-pointer rounded p-1.5 text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-red-600 dark:hover:bg-zinc-800 dark:hover:text-red-400"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Desktop table -->
                        <div class="hidden rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-x-auto md:block">
                            <table class="min-w-full text-xs">
                                <thead class="bg-zinc-50 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800">
                                    <tr>
                                        <th class="px-3 py-3 w-10">
                                            <input
                                                type="checkbox"
                                                :checked="allSelected"
                                                :indeterminate.prop="someSelected"
                                                @change="toggleAll(($event.target as HTMLInputElement).checked)"
                                                class="h-3.5 w-3.5 rounded border-zinc-300 dark:border-zinc-600 text-emerald-600 focus:ring-emerald-500 cursor-pointer"
                                            />
                                        </th>
                                        <th
                                            class="px-3 py-3 w-12 cursor-pointer select-none hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors border-r border-zinc-200 dark:border-zinc-800"
                                            @click="toggleSort('fetched_at')"
                                        >
                                            <div class="flex items-center gap-1 text-left font-semibold text-zinc-700 dark:text-zinc-300 text-[11px] uppercase tracking-widest">
                                                S/N
                                                <ArrowUp v-if="sortBy === 'fetched_at' && sortDir === 'asc'" class="h-3 w-3 text-emerald-600" />
                                                <ArrowDown v-else-if="sortBy === 'fetched_at' && sortDir === 'desc'" class="h-3 w-3 text-emerald-600" />
                                                <ArrowUpDown v-else class="h-3 w-3 opacity-40" />
                                            </div>
                                        </th>
                                        <th
                                            class="px-4 py-3 w-16 cursor-pointer select-none hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                            @click="toggleSort('fetched_at')"
                                        >
                                            <div class="flex items-center gap-1 text-left font-semibold text-zinc-700 dark:text-zinc-300 text-[11px] uppercase tracking-widest">
                                                Time
                                                <ArrowUp v-if="sortBy === 'fetched_at' && sortDir === 'asc'" class="h-3 w-3 text-emerald-600" />
                                                <ArrowDown v-else-if="sortBy === 'fetched_at' && sortDir === 'desc'" class="h-3 w-3 text-emerald-600" />
                                                <ArrowUpDown v-else class="h-3 w-3 opacity-40" />
                                            </div>
                                        </th>
                                        <th
                                            class="px-3 py-3 w-32 cursor-pointer select-none hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors"
                                            @click="toggleSort('phone')"
                                        >
                                            <div class="flex items-center gap-1 text-left font-semibold text-zinc-700 dark:text-zinc-300 text-[11px] uppercase tracking-widest">
                                                Phone
                                                <ArrowUp v-if="sortBy === 'phone' && sortDir === 'asc'" class="h-3 w-3 text-emerald-600" />
                                                <ArrowDown v-else-if="sortBy === 'phone' && sortDir === 'desc'" class="h-3 w-3 text-emerald-600" />
                                                <ArrowUpDown v-else class="h-3 w-3 opacity-40" />
                                            </div>
                                        </th>
                                        <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest">Email Address</th>
                                        <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest w-20">Source</th>
                                        <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest w-24">OTP</th>
                                        <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest w-20">Server Time</th>
                                        <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest">Message</th>
                                        <th class="text-left font-semibold text-zinc-700 dark:text-zinc-300 px-3 py-3 text-[11px] uppercase tracking-widest w-16 border-l border-zinc-200 dark:border-zinc-800">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                    <tr
                                        v-for="(entry, index) in entries"
                                        :key="entry.id"
                                        :class="selectedIds.has(entry.id) ? 'bg-blue-50 dark:bg-blue-950/30' : ''"
                                    >
                                        <td class="px-3 py-2.5">
                                            <input
                                                type="checkbox"
                                                :checked="selectedIds.has(entry.id)"
                                                @change="toggleRow(entry.id, ($event.target as HTMLInputElement).checked)"
                                                class="h-3.5 w-3.5 rounded border-zinc-300 dark:border-zinc-600 text-emerald-600 focus:ring-emerald-500 cursor-pointer"
                                            />
                                        </td>
                                        <td class="px-3 py-2.5 font-mono text-zinc-500 dark:text-zinc-400 tabular-nums text-[10px] whitespace-nowrap border-r border-zinc-200 dark:border-zinc-800">{{ (currentPage - 1) * perPage + index + 1 }}</td>
                                        <td class="px-4 py-2.5 font-mono text-zinc-500 dark:text-zinc-400 tabular-nums text-[10px] whitespace-nowrap">{{ formatTime(entry.fetched_at) }}</td>
                                        <td class="px-3 py-2.5 font-mono text-zinc-700 dark:text-zinc-300 text-[11px] whitespace-nowrap">{{ entry.phone }}</td>
                                        <td class="px-3 py-2.5 text-[11px] whitespace-nowrap">
                                            <span v-if="entry.to_address" class="font-mono text-blue-600 dark:text-blue-400">{{ entry.to_address }}</span>
                                            <span v-else class="text-zinc-400 dark:text-zinc-600">—</span>
                                        </td>
                                        <td class="px-3 py-2.5 whitespace-nowrap">
                                            <div class="flex flex-col gap-1">
                                                <!-- Channel badge -->
                                                <span
                                                    v-if="entry.source_gmail_id"
                                                    class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-semibold bg-blue-100 text-black"
                                                >
                                                    <Mail class="h-2.5 w-2.5" />Email
                                                </span>
                                                <span
                                                    v-else
                                                    class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-semibold bg-violet-100 text-black"
                                                >
                                                    <MessageSquare class="h-2.5 w-2.5" />SMS
                                                </span>
                                                <!-- IVACBD / OTHER sub-badge -->
                                                <span v-if="entry.is_ivacbd" class="inline-flex rounded px-1.5 py-0.5 text-[9px] font-semibold bg-emerald-100 text-black">IVACBD</span>
                                                <span v-else class="inline-flex rounded px-1.5 py-0.5 text-[9px] font-semibold bg-zinc-100 text-black">OTHER</span>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2.5 whitespace-nowrap">
                                            <span v-if="entry.otp_code" class="font-mono font-bold text-emerald-700 dark:text-emerald-400 text-sm tracking-wider">{{ entry.otp_code }}</span>
                                            <span v-else class="text-zinc-400 dark:text-zinc-600 text-[10px]">—</span>
                                        </td>
                                        <td class="px-3 py-2.5 font-mono tabular-nums text-[10px] whitespace-nowrap">
                                            <template v-if="entry.server_time">
                                                <div class="text-blue-600 dark:text-blue-400">{{ formatServerTime(entry.server_time).split(' ')[0] }}</div>
                                                <div class="text-blue-600 dark:text-blue-400">{{ formatServerTime(entry.server_time).split(' ').slice(1).join(' ') }}</div>
                                            </template>
                                            <span v-else class="text-zinc-400 dark:text-zinc-600">—</span>
                                        </td>
                                        <td class="px-3 py-2.5 text-zinc-600 dark:text-zinc-400 text-[11px] break-words">{{ entry.message }}</td>
                                        <td class="px-3 py-2.5 whitespace-nowrap border-l border-zinc-200 dark:border-zinc-800">
                                            <button
                                                type="button"
                                                @click="deleteOne(entry.id)"
                                                title="Delete this OTP"
                                                class="cursor-pointer rounded p-1 text-zinc-400 hover:bg-zinc-100 hover:text-red-600 dark:hover:bg-zinc-800 dark:hover:text-red-400 transition-colors"
                                            >
                                                <Trash2 class="h-3.5 w-3.5" />
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="lastPage > 1" class="flex flex-col gap-2 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs text-zinc-600 dark:text-zinc-400">
                                Page <span class="font-semibold text-zinc-900 dark:text-white">{{ currentPage }}</span> of
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ lastPage }}</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <Button size="sm" variant="outline" @click="goToPage(1)" :disabled="currentPage === 1 || loading">First</Button>
                                <Button size="sm" variant="outline" @click="goToPage(currentPage - 1)" :disabled="currentPage === 1 || loading">Prev</Button>
                                <Button size="sm" variant="outline" @click="goToPage(currentPage + 1)" :disabled="currentPage === lastPage || loading">Next</Button>
                                <Button size="sm" variant="outline" @click="goToPage(lastPage)" :disabled="currentPage === lastPage || loading">Last</Button>
                            </div>
                        </div>
                    </template>

                    <div v-else-if="!loading" class="text-center py-12 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">No OTPs for this date.</p>
                    </div>
                </div>

                <!-- RIGHT: docs + insert form -->
                <aside class="flex flex-col gap-4">
                    <!-- Docs -->
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 p-4">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">SMS Forwarder Endpoint</h2>
                        <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">Public, no auth. Both GET and POST accept the same <span class="font-mono">phone</span> + <span class="font-mono">msg</span> fields.</p>

                        <!-- Method tabs -->
                        <div class="mt-3 inline-flex rounded-md border border-zinc-200 dark:border-zinc-700 p-0.5 bg-zinc-50 dark:bg-zinc-900">
                            <button
                                type="button"
                                @click="docsMethod = 'get'"
                                class="px-3 py-1 text-[11px] font-mono font-semibold rounded transition"
                                :class="docsMethod === 'get' ? 'bg-white dark:bg-zinc-700 text-emerald-600 dark:text-emerald-400 shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                            >GET</button>
                            <button
                                type="button"
                                @click="docsMethod = 'post'"
                                class="px-3 py-1 text-[11px] font-mono font-semibold rounded transition"
                                :class="docsMethod === 'post' ? 'bg-white dark:bg-zinc-700 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                            >POST</button>
                        </div>

                        <template v-if="docsMethod === 'get'">
                            <pre class="mt-2 overflow-x-auto rounded-md bg-zinc-50 dark:bg-zinc-900 p-2 text-[10px] leading-tight text-zinc-700 dark:text-zinc-300 font-mono"><span class="text-emerald-600 dark:text-emerald-400">GET</span> /otp?phone=&lt;PHONE&gt;&amp;msg=&lt;TEXT&gt;</pre>
                            <p class="mt-3 text-[11px] font-semibold text-zinc-700 dark:text-zinc-300">Example</p>
                            <pre class="mt-1 overflow-x-auto rounded-md bg-zinc-50 dark:bg-zinc-900 p-2 text-[10px] leading-tight text-zinc-700 dark:text-zinc-300 font-mono whitespace-pre-wrap break-all">https://ipms.senda.fit/otp?phone=01352511773&amp;msg=(IVACBD)%20For%20security%2C%20type%20the%20following%20sequence%20when%20prompted%20Six-Three-Four-Eight-Zero-Seven%20.</pre>
                            <p class="mt-2 text-[10px] text-zinc-500 dark:text-zinc-400">Query values must be URL-encoded.</p>
                        </template>

                        <template v-else>
                            <pre class="mt-2 overflow-x-auto rounded-md bg-zinc-50 dark:bg-zinc-900 p-2 text-[10px] leading-tight text-zinc-700 dark:text-zinc-300 font-mono"><span class="text-blue-600 dark:text-blue-400">POST</span> /otp
Content-Type: application/json

{
  "phone": "&lt;PHONE&gt;",
  "msg": "&lt;TEXT&gt;"
}</pre>
                            <p class="mt-3 text-[11px] font-semibold text-zinc-700 dark:text-zinc-300">Example</p>
                            <pre class="mt-1 overflow-x-auto rounded-md bg-zinc-50 dark:bg-zinc-900 p-2 text-[10px] leading-tight text-zinc-700 dark:text-zinc-300 font-mono whitespace-pre-wrap break-all">curl -X POST https://ipms.senda.fit/otp \
  -H "Content-Type: application/json" \
  -d '{"phone":"01352511773","msg":"(IVACBD) For security, type the following sequence when prompted Six-Three-Four-Eight-Zero-Seven ."}'</pre>
                            <p class="mt-2 text-[10px] text-zinc-500 dark:text-zinc-400">Form-encoded bodies (<span class="font-mono">application/x-www-form-urlencoded</span>) work too. This is what the Android forwarder uses.</p>
                        </template>

                        <p class="mt-3 text-[11px] font-semibold text-zinc-700 dark:text-zinc-300">Response (both methods)</p>
                        <pre class="mt-1 overflow-x-auto rounded-md bg-zinc-50 dark:bg-zinc-900 p-2 text-[10px] leading-tight text-zinc-700 dark:text-zinc-300 font-mono">{
  "id": 1234,
  "phone": "01352511773",
  "otp_code": "634807",
  "is_ivacbd": true,
  "fetched_at": "2026-07-27 00:15:03"
}</pre>

                        <ul class="mt-3 space-y-1 text-[11px] text-zinc-600 dark:text-zinc-400">
                            <li>· <span class="font-mono">phone</span> (max 20 chars) and <span class="font-mono">msg</span> are both required — a missing field returns <span class="font-mono">422</span> when <span class="font-mono">Accept: application/json</span> is sent, otherwise a <span class="font-mono">302</span> redirect.</li>
                            <li>· POST is CSRF-exempt, so no token is needed.</li>
                            <li>· Messages starting with <span class="font-mono text-emerald-600 dark:text-emerald-400">(IVACBD)</span> or containing <span class="font-mono text-emerald-600 dark:text-emerald-400">For security, type the following sequence</span> are parsed for OTP.</li>
                            <li>· Word-form (Six-Three-...) and plain 4–8 digit runs both supported.</li>
                            <li>· Non-IVACBD messages are stored with <span class="font-mono">otp_code = null</span>.</li>
                            <li>· Timestamp inserted and displayed in BDT (Asia/Dhaka).</li>
                        </ul>
                    </div>

                    <!-- Insert form -->
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 p-4">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Manual Insert</h2>
                        <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">For testing or recovering a missed forward.</p>

                        <!-- Mode toggle -->
                        <div class="mt-3 inline-flex rounded-md border border-zinc-200 dark:border-zinc-700 p-0.5 bg-zinc-50 dark:bg-zinc-900">
                            <button
                                type="button"
                                @click="insertMode = 'message'"
                                class="px-3 py-1 text-[11px] font-medium rounded transition"
                                :class="insertMode === 'message' ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                            >SMS Message</button>
                            <button
                                type="button"
                                @click="insertMode = 'direct'"
                                class="px-3 py-1 text-[11px] font-medium rounded transition"
                                :class="insertMode === 'direct' ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                            >Direct OTP</button>
                        </div>

                        <div class="mt-3 flex flex-col gap-2.5">
                            <div class="flex flex-col gap-1">
                                <label class="text-[11px] font-medium text-zinc-700 dark:text-zinc-300">Phone</label>
                                <input
                                    v-model="insertPhone"
                                    list="account-phones"
                                    placeholder="01352511773"
                                    class="h-9 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 py-1 text-xs font-mono text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                            </div>

                            <div v-if="insertMode === 'message'" class="flex flex-col gap-1">
                                <label class="text-[11px] font-medium text-zinc-700 dark:text-zinc-300">SMS Message</label>
                                <textarea
                                    v-model="insertMessage"
                                    rows="4"
                                    placeholder="(IVACBD) For security, type the following sequence when prompted Six-Three-Four-Eight-Zero-Seven ."
                                    class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 py-1.5 text-xs text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500 resize-y"
                                ></textarea>
                                <p class="text-[10px] text-zinc-500 dark:text-zinc-400">Parsed if it starts with <span class="font-mono">(IVACBD)</span> or contains "For security, type the following sequence".</p>
                            </div>

                            <div v-else class="flex flex-col gap-1">
                                <label class="text-[11px] font-medium text-zinc-700 dark:text-zinc-300">OTP Code</label>
                                <input
                                    v-model="insertOtp"
                                    placeholder="634807"
                                    inputmode="numeric"
                                    maxlength="8"
                                    class="h-9 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 py-1 text-sm font-mono tracking-widest text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                                <p class="text-[10px] text-zinc-500 dark:text-zinc-400">4–8 digits. Marked as IVACBD.</p>
                            </div>

                            <p v-if="insertError" class="text-[11px] text-red-600 dark:text-red-400">{{ insertError }}</p>
                            <p v-if="insertSuccess" class="text-[11px] text-emerald-600 dark:text-emerald-400">{{ insertSuccess }}</p>

                            <Button size="sm" @click="submitInsert" :disabled="inserting" class="mt-1 gap-1.5">
                                <Send class="h-3.5 w-3.5" :class="{ 'animate-pulse': inserting }" />
                                {{ inserting ? 'Inserting…' : 'Insert' }}
                            </Button>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
