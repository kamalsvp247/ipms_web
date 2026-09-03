<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { Search, Plus, MoreVertical, Edit2, Trash2, Key, Cpu, Clock, Eye, EyeOff, Wallet, RefreshCw, Server, ToggleLeft, ToggleRight, Shield } from 'lucide-vue-next';
import { ref, onMounted, computed } from 'vue';
import DataTablePagination from '@/components/DataTablePagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Switch from '@/components/ui/Switch.vue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';

const SESSION_STORAGE_LIMIT_KEY = 'ipms_captcha_provider_limit';
const DEFAULT_PROVIDER_LIMIT = 20;

type CaptchaProviderType = 'capmonster' | '2captcha' | 'captchaai' | 'capsolver' | 'solvecaptcha' | 'in_house';

const PROVIDER_TYPE_OPTIONS: { value: CaptchaProviderType; label: string }[] = [
    { value: 'capmonster', label: 'CapMonster' },
    { value: '2captcha', label: '2Captcha' },
    { value: 'captchaai', label: 'CaptchaAI' },
    { value: 'capsolver', label: 'CapSolver' },
    { value: 'solvecaptcha', label: 'SolveCaptcha' },
    { value: 'in_house', label: 'In-House (self-hosted)' },
];

/** The local solver takes no API key and has no vendor balance to query. */
const isInHouse = (type: CaptchaProviderType) => type === 'in_house';

const page = usePage();
const canManageBot = computed(() => page.props.auth?.permissions?.['bot.manage'] === true);

// The in-house solver is a super-admin subsystem (page, API and sidecar all gate on
// bot.manage), so non-admins are not offered it here either. The server refuses it too.
const availableTypeOptions = computed(() =>
    canManageBot.value ? PROVIDER_TYPE_OPTIONS : PROVIDER_TYPE_OPTIONS.filter((o) => !isInHouse(o.value)),
);

interface CaptchaProvider {
    id: number;
    name: string;
    type: CaptchaProviderType;
    enabled: boolean;
    api_key?: string;
    solver_threads: number;
    params?: any;
    created_at: string;
    updated_at: string;
}

interface BalanceState {
    value: number | null;
    loading: boolean;
    error: string | null;
}

const providers = ref<CaptchaProvider[]>([]);
const balances = ref<Record<number, BalanceState>>({});
const paginationMeta = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    from: 0,
    to: 0,
});
const loading = ref(true);
const isDialogOpen = ref(false);
const editingProvider = ref<CaptchaProvider | null>(null);
const searchQuery = ref('');
const showKeys = ref(false);
const providerLimit = ref(DEFAULT_PROVIDER_LIMIT);

const form = ref({
    name: '',
    type: 'capmonster' as CaptchaProviderType,
    enabled: false,
    api_key: '',
    solver_threads: 1,
    params: {} as any,
});

const fetchBalance = async (provider: CaptchaProvider) => {
    balances.value[provider.id] = { value: null, loading: true, error: null };
    try {
        const res = await axios.get(`/api/captcha-providers/${provider.id}/balance`);
        balances.value[provider.id] = { value: res.data.balance, loading: false, error: null };
    } catch (e: any) {
        const msg = e.response?.data?.error ?? 'Failed';
        balances.value[provider.id] = { value: null, loading: false, error: msg };
    }
};

const checkingBalances = ref(false);

const fetchAllBalances = async (list: CaptchaProvider[]) => {
    checkingBalances.value = true;
    await Promise.allSettled(list.filter((p) => !isInHouse(p.type)).map((p) => fetchBalance(p)));
    checkingBalances.value = false;
};

const fetchProviders = async (page = 1) => {
    loading.value = true;
    try {
        const response = await axios.get('/api/captcha-providers', {
            params: {
                page,
                search: searchQuery.value,
                per_page: 10,
            },
        });
        providers.value = response.data.data;
        paginationMeta.value = {
            current_page: response.data.current_page,
            last_page: response.data.last_page,
            total: response.data.total,
            from: response.data.from,
            to: response.data.to,
        };
        providers.value.forEach((p) => {
            if (!balances.value[p.id]) {
                balances.value[p.id] = { value: null, loading: false, error: null };
            }
        });
    } catch (error) {
        console.error('Failed to fetch providers', error);
    } finally {
        loading.value = false;
    }
};

let searchTimeout: any;
const onSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchProviders(1);
    }, 500);
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString() + ' ' + new Date(date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

const typeLabel = (type: CaptchaProviderType) => {
    return PROVIDER_TYPE_OPTIONS.find((o) => o.value === type)?.label ?? type;
};

const openCreateDialog = () => {
    editingProvider.value = null;
    form.value = {
        name: '',
        type: 'capmonster',
        enabled: false,
        api_key: '',
        solver_threads: 1,
        params: {},
    };
    isDialogOpen.value = true;
};

const openEditDialog = (provider: CaptchaProvider) => {
    editingProvider.value = provider;
    form.value = {
        name: provider.name,
        type: provider.type,
        enabled: provider.enabled,
        api_key: provider.api_key ?? '',
        solver_threads: provider.solver_threads,
        params: provider.params ?? {},
    };
    isDialogOpen.value = true;
};

const saveProvider = async () => {
    // Drop any key typed before the type was switched — an in-house row must not carry a
    // vendor secret, and the field that would let you clear it is hidden.
    const payload = { ...form.value, api_key: isInHouse(form.value.type) ? '' : form.value.api_key };

    try {
        if (editingProvider.value) {
            await axios.put(`/api/captcha-providers/${editingProvider.value.id}`, payload);
        } else {
            await axios.post('/api/captcha-providers', payload);
        }
        isDialogOpen.value = false;
        fetchProviders();
    } catch (error) {
        console.error('Failed to save provider', error);
    }
};

const deleteProvider = async (id: number) => {
    if (!confirm('Are you sure you want to delete this provider?')) return;
    try {
        await axios.delete(`/api/captcha-providers/${id}`);
        fetchProviders();
    } catch (error) {
        console.error('Failed to delete provider', error);
    }
};

const updateProviderStatus = async (provider: CaptchaProvider, enabled: boolean) => {
    if (provider.enabled === enabled) return;
    try {
        await axios.put(`/api/captcha-providers/${provider.id}`, { enabled });
        fetchProviders();
    } catch (error) {
        console.error('Failed to update status', error);
    }
};

const bulkStatusLoading = ref(false);
const allEnabled = computed(() => providers.value.length > 0 && providers.value.every(p => p.enabled));

const bulkStatus = async (enabled: boolean) => {
    bulkStatusLoading.value = true;
    try {
        await axios.post('/api/captcha-providers/bulk-status', { enabled });
        fetchProviders();
    } catch (error) {
        console.error('Failed to bulk update status', error);
    } finally {
        bulkStatusLoading.value = false;
    }
};

function loadProviderLimitFromSession() {
    try {
        const stored = sessionStorage.getItem(SESSION_STORAGE_LIMIT_KEY);
        if (stored !== null) {
            const n = parseInt(stored, 10);
            if (n >= 1 && n <= 20) providerLimit.value = n;
        }
    } catch {
        // ignore
    }
}

function setProviderLimit(value: number) {
    const n = Math.min(20, Math.max(1, value));
    providerLimit.value = n;
    try {
        sessionStorage.setItem(SESSION_STORAGE_LIMIT_KEY, String(n));
    } catch {
        // ignore
    }
}

const atProviderLimit = computed(() => paginationMeta.value.total >= providerLimit.value);

onMounted(() => {
    loadProviderLimitFromSession();
    fetchProviders();
});

const breadcrumbs = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Captcha Providers', href: '/captcha-providers' },
];
</script>

<template>

    <Head title="Captcha Provider Hub" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full w-full max-w-7xl flex-1 flex-col gap-6 p-6">
            <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-400 to-emerald-600 shadow-sm shadow-emerald-500/30">
                        <Shield class="h-5 w-5 text-white" />
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Captcha Providers</h2>
                        <p class="text-muted-foreground mt-0.5 text-sm">Configure global captcha solving services for automation.</p>
                    </div>
                </div>
                <div class="flex flex-col gap-2 items-start md:items-end">
                    <div class="flex items-center gap-2">
                        <Label for="provider-limit" class="text-sm text-muted-foreground whitespace-nowrap">Max providers</Label>
                        <Input
                            id="provider-limit"
                            type="number"
                            min="1"
                            max="20"
                            class="w-16 text-center"
                            :model-value="providerLimit"
                            @update:model-value="(v: string) => setProviderLimit(parseInt(v, 10) || 1)"
                        />
                        <Button
                            @click="openCreateDialog"
                            :disabled="atProviderLimit"
                        >
                            <Plus class="mr-2 h-4 w-4" />
                            Add Provider
                        </Button>
                    </div>
                    <p v-if="atProviderLimit" class="text-xs text-muted-foreground">
                        At limit ({{ providerLimit }}). Increase limit above to add more.
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="relative w-full sm:w-auto sm:flex-1 sm:max-w-sm">
                    <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input v-model="searchQuery" placeholder="Search by provider name..." class="pl-9 w-full"
                        @input="onSearch" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="sm" @click="fetchAllBalances(providers)" :disabled="checkingBalances || providers.length === 0" class="gap-1.5">
                        <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': checkingBalances }" />
                        {{ checkingBalances ? 'Checking…' : 'Check Balance' }}
                    </Button>
                    <Button variant="outline" size="sm" @click="showKeys = !showKeys">
                        <component :is="showKeys ? EyeOff : Eye" class="mr-2 h-4 w-4" />
                        {{ showKeys ? 'Hide' : 'Show' }} Keys
                    </Button>
                    <Button variant="outline" size="sm" @click="bulkStatus(!allEnabled)" :disabled="bulkStatusLoading || providers.length === 0"
                        class="gap-1.5"
                        :class="allEnabled
                            ? 'text-zinc-500 hover:bg-zinc-50 dark:hover:bg-zinc-900'
                            : 'text-emerald-600 border-emerald-300 hover:bg-emerald-50 dark:text-emerald-400 dark:border-emerald-700 dark:hover:bg-emerald-950/40'"
                    >
                        <ToggleRight v-if="!allEnabled" class="h-4 w-4" />
                        <ToggleLeft v-else class="h-4 w-4" />
                        {{ allEnabled ? 'Disable All' : 'Enable All' }}
                    </Button>
                </div>
            </div>

            <div class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm overflow-hidden">
                <div class="overflow-x-auto">
                <Table class="border-b min-w-[50rem]">
                    <TableHeader class="bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm border-b border-zinc-200/60 dark:border-zinc-700/60">
                        <TableRow>
                            <TableHead class="pl-3 pr-2 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest border-r w-[3.125rem]">S/N</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Provider</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Type</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Status</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Execution Details</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Balance</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Created At</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Updated At</TableHead>
                            <TableHead class="pl-2 pr-3 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest border-l w-[3.75rem]">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-if="loading" v-for="i in 3" :key="i">
                            <TableCell v-for="j in 9" :key="j" :class="{ 'border-r': j === 1, 'border-l': j === 9 }">
                                <div class="h-5 w-full animate-pulse rounded bg-muted"></div>
                            </TableCell>
                        </TableRow>

                        <TableRow v-else-if="providers.length === 0" class="h-24 text-center">
                            <TableCell colspan="9" class="text-muted-foreground">
                                No captcha providers found.
                            </TableCell>
                        </TableRow>

                        <TableRow v-else v-for="(provider, index) in providers" :key="provider.id"
                            class="hover:bg-zinc-50 dark:hover:bg-zinc-900/60 transition-colors">
                            <TableCell class="pl-3 pr-2 py-1.5 text-center text-[10px] text-zinc-400 dark:text-zinc-600 font-mono tabular-nums border-r">
                                {{ (paginationMeta.current_page - 1) * 10 + index + 1 }}
                            </TableCell>
                            <TableCell class="px-2 py-1.5 font-semibold">
                                <div class="flex items-center gap-2">
                                    <div class="p-1.5 rounded bg-blue-100 dark:bg-blue-900/20">
                                        <Cpu class="h-3.5 w-3.5 text-blue-600 dark:text-blue-400" />
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-[11px]">{{ provider.name }}</span>
                                        <span class="text-[10px] text-zinc-400">ID: {{ provider.id }}</span>
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell class="px-2 py-1.5">
                                <Badge variant="outline" class="text-[10px] font-mono">
                                    {{ typeLabel(provider.type) }}
                                </Badge>
                            </TableCell>
                            <TableCell class="px-2 py-1.5">
                                <div class="flex items-center gap-2">
                                    <Switch :model-value="provider.enabled"
                                        @update:model-value="(val: boolean) => updateProviderStatus(provider, val)" />
                                    <span class="text-[10px] font-medium"
                                        :class="provider.enabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400'">
                                        {{ provider.enabled ? 'Active' : 'Paused' }}
                                    </span>
                                </div>
                            </TableCell>
                            <TableCell class="px-2 py-1.5">
                                <div class="flex flex-col gap-0.5 text-[10px] text-zinc-600 dark:text-zinc-400">
                                    <div class="flex items-center gap-1">
                                        <Server v-if="isInHouse(provider.type)" class="h-3 w-3" />
                                        <Key v-else class="h-3 w-3" />
                                        <span class="font-mono">{{ isInHouse(provider.type) ? 'Local sidecar' :
                                            (showKeys ? provider.api_key :
                                            (provider.api_key ? '••••' + provider.api_key.slice(-4) : 'No API Key'))
                                        }}</span>
                                    </div>
                                    <div class="flex items-center gap-1 font-mono">
                                        <Clock class="h-3 w-3" />
                                        <span>{{ provider.solver_threads }} threads</span>
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell class="px-2 py-1.5">
                                <div class="flex items-center gap-1.5">
                                    <template v-if="isInHouse(provider.type)">
                                        <Server class="h-3 w-3 text-teal-500" />
                                        <span class="text-[10px] font-medium text-teal-600 dark:text-teal-400">Self-hosted</span>
                                    </template>
                                    <template v-else-if="balances[provider.id]?.loading">
                                        <RefreshCw class="h-3 w-3 text-zinc-400 animate-spin" />
                                        <span class="text-[10px] text-zinc-400">Loading...</span>
                                    </template>
                                    <template v-else-if="balances[provider.id]?.error">
                                        <Wallet class="h-3 w-3 text-red-400" />
                                        <span class="text-[10px] text-red-400" :title="balances[provider.id]?.error ?? ''">Error</span>
                                        <button @click="fetchBalance(provider)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                            <RefreshCw class="h-3 w-3" />
                                        </button>
                                    </template>
                                    <template v-else-if="balances[provider.id]?.value !== null && balances[provider.id]?.value !== undefined">
                                        <Wallet class="h-3 w-3 text-emerald-500" />
                                        <span class="text-[11px] font-semibold font-mono text-emerald-600 dark:text-emerald-400">
                                            ${{ balances[provider.id]?.value?.toFixed(3) }}
                                        </span>
                                        <button @click="fetchBalance(provider)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                            <RefreshCw class="h-3 w-3" />
                                        </button>
                                    </template>
                                    <template v-else>
                                        <Wallet class="h-3 w-3 text-zinc-300" />
                                        <span class="text-[10px] text-zinc-400">—</span>
                                        <button @click="fetchBalance(provider)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                            <RefreshCw class="h-3 w-3" />
                                        </button>
                                    </template>
                                </div>
                            </TableCell>
                            <TableCell class="px-2 py-1.5">
                                <span class="text-[10px] text-zinc-400">{{ formatDate(provider.created_at) }}</span>
                            </TableCell>
                            <TableCell class="px-2 py-1.5">
                                <span class="text-[10px] text-zinc-400">{{ formatDate(provider.updated_at) }}</span>
                            </TableCell>
                            <TableCell class="pl-2 pr-3 py-1.5 text-center border-l">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button variant="ghost" size="icon" class="h-8 w-8">
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" class="w-40">
                                        <DropdownMenuLabel>Manage</DropdownMenuLabel>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem @click="openEditDialog(provider)">
                                            <Edit2 class="mr-2 h-4 w-4" /> Edit
                                        </DropdownMenuItem>
                                        <DropdownMenuItem @click="deleteProvider(provider.id)" class="text-destructive">
                                            <Trash2 class="mr-2 h-4 w-4" /> Remove
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                </div>

                <DataTablePagination :meta="paginationMeta" @page-change="fetchProviders" />
            </div>

            <!-- Provider Dialog -->
            <Dialog v-model:open="isDialogOpen">
                <DialogContent class="sm:max-w-[26.5625rem] p-0 flex flex-col">
                    <DialogHeader class="p-3 border-b border-zinc-200 dark:border-zinc-800">
                        <DialogTitle class="text-[14px] font-semibold">{{ editingProvider ? 'Update Provider' : 'New Provider' }}</DialogTitle>
                        <DialogDescription class="text-[11px]">Configure the API connection and resource allocation for {{ editingProvider?.name || 'the service' }}.</DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-1.5 py-3 px-3">
                        <div class="grid gap-1.5">
                            <Label for="type" class="text-[11px] font-semibold">Provider Type</Label>
                            <select
                                id="type"
                                v-model="form.type"
                                class="h-8 w-full rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-2 text-[11px]"
                            >
                                <option v-for="opt in availableTypeOptions" :key="opt.value" :value="opt.value">
                                    {{ opt.label }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="name" class="text-[11px] font-semibold">Display Name</Label>
                            <Input id="name" v-model="form.name" placeholder="e.g. My CapMonster Key 1" class="h-8 text-[11px]" />
                        </div>
                        <div v-if="!isInHouse(form.type)" class="grid gap-1.5">
                            <Label for="api_key" class="text-[11px] font-semibold">API Secret Key</Label>
                            <Input id="api_key" type="password" v-model="form.api_key"
                                placeholder="Enter service API key" class="h-8 text-[11px]" />
                        </div>
                        <div v-else class="flex items-start gap-2 rounded border border-teal-200 bg-teal-50/60 p-2 text-[10px] text-teal-800 dark:border-teal-900 dark:bg-teal-950/40 dark:text-teal-300">
                            <Server class="mt-0.5 h-3 w-3 flex-shrink-0" />
                            <span>
                                No API key &mdash; solved locally by the <span class="font-mono">ipms-in-house-captcha</span> sidecar,
                                using the site key and page URL from Settings. Verify it on the In-House Captcha page first.
                            </span>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div class="grid gap-1.5">
                                <Label for="threads" class="text-[11px] font-semibold">Solver Threads</Label>
                                <Input id="threads" type="number" v-model="form.solver_threads" class="h-8 text-[11px]" />
                            </div>
                            <div class="flex flex-col justify-end gap-1.5">
                                <Label for="enabled" class="text-[11px] font-semibold">Active Status</Label>
                                <div class="flex items-center justify-between rounded border border-zinc-200 dark:border-zinc-700 p-2 bg-zinc-50/60 dark:bg-zinc-900/40 h-8">
                                    <span class="text-[10px] text-zinc-600 dark:text-zinc-400">{{ form.enabled ? 'On' : 'Off' }}</span>
                                    <Switch id="enabled" :model-value="form.enabled"
                                        @update:model-value="(v: boolean) => form.enabled = v" />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="p-3 border-t border-zinc-200 dark:border-zinc-800 flex gap-2">
                        <Button variant="outline" @click="isDialogOpen = false" class="h-8 text-[11px]">Cancel</Button>
                        <Button @click="saveProvider" class="h-8 text-[11px] bg-emerald-600 hover:bg-emerald-700 text-white">{{ editingProvider ? 'Save Changes' : 'Add Provider' }}</Button>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
