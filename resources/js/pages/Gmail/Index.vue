<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Mail,
    RefreshCw,
    Trash2,
    Unlink,
    ChevronLeft,
    ChevronRight,
    X,
    AlertTriangle,
} from 'lucide-vue-next';
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useToast } from 'vue-toastification';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface GmailMessage {
    id: number;
    gmail_id: string;
    from: string | null;
    to_address: string | null;
    phone: string | null;
    subject: string | null;
    snippet: string | null;
    received_at: string | null;
    is_unread: boolean;
}

interface PaginatedMessages {
    data: GmailMessage[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

interface FullMessage {
    gmail_id: string;
    from: string | null;
    to_address: string | null;
    subject: string | null;
    snippet: string | null;
    received_at: string | null;
    body: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Gmail', href: '/gmail' },
];

const toast = useToast();

const connected = ref(false);
const connectedEmail = ref('');
const messages = ref<GmailMessage[]>([]);
const currentPage = ref(1);
const lastPage = ref(1);
const total = ref(0);
const perPage = ref<25 | 50 | 100>(25);
const loading = ref(false);
const refreshing = ref(false);

const selectedIds = ref<Set<string>>(new Set());

const drawerOpen = ref(false);
const drawerLoading = ref(false);
const drawerMessage = ref<FullMessage | null>(null);

const clearAllModal = ref(false);
const clearAllConfirm = ref('');
const clearAllPermanent = ref(false);
const clearAllLoading = ref(false);
const clearAllProgress = ref('');

const deletingSelected = ref(false);
const disconnecting = ref(false);

const selectedCount = computed(() => selectedIds.value.size);
const allSelected = computed(() =>
    messages.value.length > 0 && messages.value.every((m) => selectedIds.value.has(m.gmail_id)),
);

function toggleMaster() {
    if (allSelected.value) {
        messages.value.forEach((m) => selectedIds.value.delete(m.gmail_id));
    } else {
        messages.value.forEach((m) => selectedIds.value.add(m.gmail_id));
    }
}

function toggleRow(id: string) {
    if (selectedIds.value.has(id)) {
        selectedIds.value.delete(id);
    } else {
        selectedIds.value.add(id);
    }
}

async function fetchMessages(page = 1) {
    loading.value = true;
    try {
        const resp = await axios.get('/api/gmail/messages', { params: { page, per_page: perPage.value } });
        connected.value = resp.data.connected;
        if (resp.data.connected) {
            connectedEmail.value = resp.data.email;
            const paged: PaginatedMessages = resp.data.messages;
            messages.value = paged.data;
            currentPage.value = paged.current_page;
            lastPage.value = paged.last_page;
            total.value = paged.total;
        }
    } catch {
        toast.error('Failed to load messages.');
    } finally {
        loading.value = false;
    }
}

async function refresh() {
    refreshing.value = true;
    await fetchMessages(currentPage.value);
    refreshing.value = false;
}

async function openMessage(gmailId: string) {
    drawerOpen.value = true;
    drawerLoading.value = true;
    drawerMessage.value = null;
    try {
        const resp = await axios.get(`/api/gmail/messages/${gmailId}`);
        drawerMessage.value = resp.data;
    } catch {
        toast.error('Failed to load message.');
        drawerOpen.value = false;
    } finally {
        drawerLoading.value = false;
    }
}

async function deleteSelected(permanent = false) {
    const ids = Array.from(selectedIds.value);
    if (!ids.length) { return; }
    deletingSelected.value = true;
    try {
        const endpoint = permanent ? '/api/gmail/messages/batch-delete' : '/api/gmail/messages/batch-trash';
        await axios.post(endpoint, { ids });
        selectedIds.value.clear();
        toast.success(`${ids.length} message(s) ${permanent ? 'deleted' : 'moved to trash'}.`);
        await fetchMessages(currentPage.value);
    } catch {
        toast.error('Delete failed.');
    } finally {
        deletingSelected.value = false;
    }
}

async function clearAll() {
    if (clearAllConfirm.value !== 'DELETE') { return; }
    clearAllLoading.value = true;
    clearAllProgress.value = 'Clearing...';
    try {
        const resp = await axios.post('/api/gmail/messages/clear-all', { permanent: clearAllPermanent.value });
        toast.success(`${resp.data.deleted} messages cleared.`);
        clearAllModal.value = false;
        clearAllConfirm.value = '';
        await fetchMessages(1);
    } catch {
        toast.error('Clear all failed.');
    } finally {
        clearAllLoading.value = false;
        clearAllProgress.value = '';
    }
}

async function disconnect() {
    if (!confirm('Disconnect your Google account?')) { return; }
    disconnecting.value = true;
    try {
        await axios.delete('/auth/google');
        router.visit('/gmail');
    } catch {
        toast.error('Disconnect failed.');
        disconnecting.value = false;
    }
}

function formatDate(dateStr: string | null): string {
    if (!dateStr) { return '—'; }
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: 'Asia/Dhaka' }) + ' ' +
        d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'Asia/Dhaka' });
}

function connectGoogle() {
    window.location.href = '/auth/google';
}

let echoChannel: ReturnType<typeof window.Echo.private> | null = null;

function subscribeEcho(userId: number) {
    echoChannel = window.Echo.private(`gmail.${userId}`)
        .listen('.message.received', (e: { message: GmailMessage }) => {
            if (currentPage.value === 1) {
                messages.value.unshift(e.message);
                if (messages.value.length > perPage.value) {
                    messages.value.pop();
                }
                total.value++;
            }
        });
}

onMounted(async () => {
    await fetchMessages(1);
    // @ts-ignore - user injected by Inertia shared props
    const userId = (window as any).__INERTIA_PAGE_PROPS__?.auth?.user?.id;
    if (userId && connected.value) {
        subscribeEcho(userId);
    }
});

onBeforeUnmount(() => {
    if (echoChannel) {
        window.Echo.leave(`gmail.${connectedEmail.value}`);
    }
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Gmail" />

        <div class="flex flex-col gap-3 p-4">

            <!-- Not connected state -->
            <template v-if="!connected && !loading">
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 flex flex-col items-center justify-center gap-3 py-16">
                    <div class="rounded-full bg-zinc-100 dark:bg-zinc-900 p-4">
                        <Mail class="text-zinc-400 size-8" />
                    </div>
                    <p class="text-zinc-500 dark:text-zinc-400 text-xs">Connect your Google account to view Gmail messages.</p>
                    <Button @click="connectGoogle" size="sm" class="gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white">
                        <Mail class="size-3.5" />
                        Connect Google Account
                    </Button>
                </div>
            </template>

            <!-- Connected inbox -->
            <template v-if="connected">

                <!-- Top bar -->
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 px-3 py-2 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="rounded bg-emerald-100 text-black px-1.5 py-0.5 text-[10px] font-semibold font-mono">{{ connectedEmail }}</span>
                        <span class="text-zinc-500 text-[11px] tabular-nums">{{ total }} messages</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button
                            :disabled="refreshing"
                            @click="refresh"
                            class="inline-flex items-center gap-1 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2.5 py-1 text-xs text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800 disabled:opacity-50"
                        >
                            <RefreshCw class="size-3" :class="{ 'animate-spin': refreshing }" />
                            Refresh
                        </button>
                        <button
                            @click="clearAllModal = true"
                            class="inline-flex items-center gap-1 rounded border border-red-200 dark:border-red-900/40 bg-white dark:bg-zinc-900 px-2.5 py-1 text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/20"
                        >
                            <Trash2 class="size-3" />
                            Clear All
                        </button>
                        <button
                            :disabled="disconnecting"
                            @click="disconnect"
                            class="inline-flex items-center gap-1 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2.5 py-1 text-xs text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800 disabled:opacity-50"
                        >
                            <Unlink class="size-3" />
                            Disconnect
                        </button>
                    </div>
                </div>

                <!-- Selection bar -->
                <div v-if="selectedCount > 0" class="rounded border border-emerald-200 dark:border-emerald-900/40 bg-emerald-50 dark:bg-emerald-950/10 flex items-center gap-2 px-3 py-1.5">
                    <span class="text-emerald-700 dark:text-emerald-300 text-[11px] font-semibold tabular-nums">{{ selectedCount }} selected</span>
                    <button
                        :disabled="deletingSelected"
                        @click="deleteSelected(false)"
                        class="inline-flex items-center gap-1 rounded border border-zinc-200 dark:border-zinc-700 px-2 py-0.5 text-[11px] text-orange-600 dark:text-orange-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 disabled:opacity-50"
                    >
                        <Trash2 class="size-3" />
                        Trash ({{ selectedCount }})
                    </button>
                    <button
                        :disabled="deletingSelected"
                        @click="deleteSelected(true)"
                        class="inline-flex items-center gap-1 rounded border border-zinc-200 dark:border-zinc-700 px-2 py-0.5 text-[11px] text-red-600 dark:text-red-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 disabled:opacity-50"
                    >
                        <Trash2 class="size-3" />
                        Delete ({{ selectedCount }})
                    </button>
                </div>

                <!-- Table -->
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-zinc-50 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800">
                                <th class="pl-3 pr-2 py-1.5 text-left w-6">
                                    <Checkbox
                                        :checked="allSelected"
                                        @update:checked="toggleMaster"
                                        class="border-zinc-300 dark:border-zinc-700 size-3.5"
                                    />
                                </th>
                                <th class="pl-2 pr-2 py-1.5 text-left text-[10px] uppercase tracking-widest font-semibold text-zinc-400 w-8">#</th>
                                <th class="px-2 py-1.5 text-left text-[10px] uppercase tracking-widest font-semibold text-zinc-400">From</th>
                                <th class="px-2 py-1.5 text-left text-[10px] uppercase tracking-widest font-semibold text-zinc-400">Email</th>
                                <th class="px-2 py-1.5 text-left text-[10px] uppercase tracking-widest font-semibold text-zinc-400">Phone</th>
                                <th class="px-2 py-1.5 text-left text-[10px] uppercase tracking-widest font-semibold text-zinc-400">Subject</th>
                                <th class="hidden px-2 py-1.5 text-left text-[10px] uppercase tracking-widest font-semibold text-zinc-400 md:table-cell">Snippet</th>
                                <th class="pl-2 pr-3 py-1.5 text-right text-[10px] uppercase tracking-widest font-semibold text-zinc-400">Received</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                            <tr v-if="loading">
                                <td colspan="8" class="text-zinc-500 py-8 text-center text-xs">Loading...</td>
                            </tr>
                            <tr v-else-if="messages.length === 0">
                                <td colspan="8" class="text-zinc-500 py-8 text-center text-xs">No messages.</td>
                            </tr>
                            <tr
                                v-for="(msg, idx) in messages"
                                :key="msg.gmail_id"
                                class="hover:bg-zinc-50 dark:hover:bg-zinc-900/60 cursor-pointer"
                                :class="{ 'bg-emerald-50/40 dark:bg-emerald-950/10': selectedIds.has(msg.gmail_id) }"
                            >
                                <td class="pl-3 pr-2 py-1.5" @click.stop>
                                    <Checkbox
                                        :checked="selectedIds.has(msg.gmail_id)"
                                        @update:checked="toggleRow(msg.gmail_id)"
                                        class="border-zinc-300 dark:border-zinc-700 size-3.5"
                                    />
                                </td>
                                <td
                                    class="pl-2 pr-2 py-1.5 text-[10px] text-zinc-300 dark:text-zinc-600 tabular-nums select-none"
                                    @click="openMessage(msg.gmail_id)"
                                >
                                    {{ (currentPage - 1) * perPage + idx + 1 }}
                                </td>
                                <td
                                    class="max-w-[10rem] truncate px-2 py-1.5 text-[11px]"
                                    :class="msg.is_unread ? 'text-zinc-900 dark:text-zinc-100 font-semibold' : 'text-zinc-600 dark:text-zinc-300'"
                                    @click="openMessage(msg.gmail_id)"
                                >
                                    {{ msg.from || '(unknown)' }}
                                </td>
                                <td
                                    class="max-w-[11.25rem] truncate px-2 py-1.5 text-zinc-500 dark:text-zinc-400 text-[11px] font-mono"
                                    @click="openMessage(msg.gmail_id)"
                                >
                                    {{ msg.to_address || '—' }}
                                </td>
                                <td
                                    class="px-2 py-1.5 text-[11px] font-mono whitespace-nowrap"
                                    :class="msg.phone ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400 dark:text-zinc-600'"
                                    @click="openMessage(msg.gmail_id)"
                                >
                                    {{ msg.phone || '—' }}
                                </td>
                                <td
                                    class="max-w-[15rem] truncate px-2 py-1.5 text-[11px]"
                                    :class="msg.is_unread ? 'text-zinc-900 dark:text-zinc-100 font-semibold' : 'text-zinc-600 dark:text-zinc-300'"
                                    @click="openMessage(msg.gmail_id)"
                                >
                                    {{ msg.subject || '(no subject)' }}
                                </td>
                                <td
                                    class="text-zinc-500 hidden max-w-[18.75rem] truncate px-2 py-1.5 text-[11px] md:table-cell"
                                    @click="openMessage(msg.gmail_id)"
                                >
                                    {{ msg.snippet }}
                                </td>
                                <td
                                    class="text-zinc-500 dark:text-zinc-400 pl-2 pr-3 py-1.5 text-right text-[11px] font-mono tabular-nums whitespace-nowrap"
                                    @click="openMessage(msg.gmail_id)"
                                >
                                    {{ formatDate(msg.received_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <!-- Pagination footer strip -->
                    <div class="pl-3 pr-3 py-1.5 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between text-[10px] text-zinc-400 tabular-nums">
                        <div class="flex items-center gap-2">
                            <span class="uppercase tracking-widest">Per page</span>
                            <select
                                v-model="perPage"
                                class="rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 py-0.5 text-[11px] text-zinc-600 dark:text-zinc-300 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                @change="fetchMessages(1)"
                            >
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span>{{ currentPage }} / {{ lastPage }}</span>
                            <button
                                :disabled="currentPage <= 1 || loading"
                                @click="fetchMessages(currentPage - 1)"
                                class="inline-flex items-center justify-center rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 size-5 text-zinc-500 hover:bg-zinc-50 dark:hover:bg-zinc-800 disabled:opacity-40"
                            >
                                <ChevronLeft class="size-3" />
                            </button>
                            <button
                                :disabled="currentPage >= lastPage || loading"
                                @click="fetchMessages(currentPage + 1)"
                                class="inline-flex items-center justify-center rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 size-5 text-zinc-500 hover:bg-zinc-50 dark:hover:bg-zinc-800 disabled:opacity-40"
                            >
                                <ChevronRight class="size-3" />
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Message drawer -->
        <transition name="drawer">
            <div
                v-if="drawerOpen"
                class="fixed inset-y-0 right-0 z-50 flex w-full max-w-xl flex-col bg-white dark:bg-zinc-950 border-l border-zinc-200 dark:border-zinc-800 shadow-xl"
            >
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900 px-3 py-2">
                    <h3 class="text-zinc-800 dark:text-zinc-200 truncate text-xs font-semibold">
                        {{ drawerMessage?.subject || '(no subject)' }}
                    </h3>
                    <button class="inline-flex size-6 items-center justify-center rounded text-zinc-500 hover:bg-zinc-200 dark:hover:bg-zinc-800" @click="drawerOpen = false">
                        <X class="size-3.5" />
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-3">
                    <div v-if="drawerLoading" class="text-zinc-500 py-10 text-center text-xs">Loading...</div>
                    <template v-else-if="drawerMessage">
                        <div class="mb-3 grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 text-[11px]">
                            <span class="text-[10px] uppercase tracking-widest text-zinc-400">From</span>
                            <span class="text-zinc-700 dark:text-zinc-300 font-mono">{{ drawerMessage.from }}</span>
                            <span class="text-[10px] uppercase tracking-widest text-zinc-400">To</span>
                            <span class="text-zinc-700 dark:text-zinc-300 font-mono">{{ drawerMessage.to_address }}</span>
                            <span class="text-[10px] uppercase tracking-widest text-zinc-400">Date</span>
                            <span class="text-zinc-700 dark:text-zinc-300 font-mono tabular-nums">{{ formatDate(drawerMessage.received_at) }}</span>
                        </div>
                        <div class="rounded border border-blue-200 dark:border-blue-900/40 bg-blue-50 dark:bg-blue-950/20 p-2.5">
                            <div class="text-[10px] font-semibold uppercase tracking-widest mb-1 text-blue-600 dark:text-blue-400">Body</div>
                            <div class="text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap text-[11px] leading-relaxed">
                                {{ drawerMessage.body || '(no body)' }}
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </transition>
        <div v-if="drawerOpen" class="fixed inset-0 z-40 bg-black/40" @click="drawerOpen = false" />

        <!-- Clear All modal -->
        <div v-if="clearAllModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-black/50" @click="clearAllModal = false" />
            <div class="relative z-10 w-full max-w-sm rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 p-4 shadow-xl">
                <div class="mb-3 flex items-center gap-2">
                    <AlertTriangle class="text-red-500 size-4 shrink-0" />
                    <h2 class="text-zinc-900 dark:text-zinc-100 text-sm font-semibold">Clear All Messages</h2>
                </div>
                <p class="text-zinc-600 dark:text-zinc-400 mb-3 text-xs">
                    This will remove all messages from INBOX.
                    Type <span class="text-red-600 dark:text-red-400 font-mono font-semibold">DELETE</span> to confirm.
                </p>

                <label class="mb-2 flex items-center gap-2 text-xs">
                    <input v-model="clearAllPermanent" type="checkbox" class="rounded border-zinc-300 dark:border-zinc-700" />
                    <span class="text-zinc-700 dark:text-zinc-300">Permanently delete</span>
                    <span class="text-zinc-400 text-[10px]">(bypasses Trash)</span>
                </label>

                <input
                    v-model="clearAllConfirm"
                    type="text"
                    placeholder="Type DELETE"
                    class="mb-3 w-full rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2.5 py-1 text-xs text-zinc-700 dark:text-zinc-200 placeholder:text-zinc-400 focus:outline-none focus:ring-1 focus:ring-red-500"
                />

                <div class="flex justify-end gap-1.5">
                    <button
                        @click="clearAllModal = false"
                        class="rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2.5 py-1 text-xs text-zinc-600 dark:text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800"
                    >Cancel</button>
                    <button
                        :disabled="clearAllConfirm !== 'DELETE' || clearAllLoading"
                        @click="clearAll"
                        class="rounded border border-red-500 bg-red-600 hover:bg-red-700 px-2.5 py-1 text-xs font-medium text-white disabled:opacity-50"
                    >
                        <span v-if="clearAllLoading">{{ clearAllProgress || 'Clearing...' }}</span>
                        <span v-else>Clear All</span>
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.drawer-enter-active,
.drawer-leave-active {
    transition: transform 0.25s ease;
}
.drawer-enter-from,
.drawer-leave-to {
    transform: translateX(100%);
}
</style>
