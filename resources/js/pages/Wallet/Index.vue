<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';

const toast = useToast();
import axios from 'axios';
import { Wallet as WalletIcon, Plus, MoreVertical, Edit2, Trash2, Eye, EyeOff, Smartphone } from 'lucide-vue-next';
import { ref, onMounted } from 'vue';
import { useToast } from 'vue-toastification';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

// Mirrors App\Support\AutoPaymentMethods. Rocket only for now; Nagad stays listed but disabled so
// any wallet already saved with it still shows its label instead of a raw key.
const METHOD_OPTIONS = [
    { value: 'rocket', label: 'Rocket', supported: true },
    { value: 'nagad', label: 'Nagad', supported: false },
] as const;

const methodLabel = (method: string): string => METHOD_OPTIONS.find((m) => m.value === method)?.label ?? method;

interface WalletEntry {
    id: number;
    method: 'rocket' | 'nagad';
    wallet_number: string;
    label?: string | null;
    created_at: string;
}

const wallets = ref<WalletEntry[]>([]);
const loading = ref(true);
const isDialogOpen = ref(false);
const editingWallet = ref<WalletEntry | null>(null);
const savingWallet = ref(false);
const formErrors = ref<string[]>([]);
const revealedPins = ref<Record<number, string>>({});
const showFormPin = ref(false);

const form = ref({
    method: 'rocket' as 'rocket' | 'nagad',
    wallet_number: '',
    pin: '',
    label: '',
});

const fetchWallets = async () => {
    loading.value = true;
    try {
        const response = await axios.get('/api/wallets');
        wallets.value = response.data;
    } catch (error) {
        toast.error('Failed to load wallets.');
    } finally {
        loading.value = false;
    }
};

const openCreateDialog = () => {
    editingWallet.value = null;
    formErrors.value = [];
    showFormPin.value = false;
    form.value = { method: 'rocket', wallet_number: '', pin: '', label: '' };
    isDialogOpen.value = true;
};

const openEditDialog = (wallet: WalletEntry) => {
    editingWallet.value = wallet;
    formErrors.value = [];
    showFormPin.value = false;
    form.value = {
        method: wallet.method,
        wallet_number: wallet.wallet_number,
        // Left blank deliberately: an empty PIN on save means "keep the stored one".
        pin: '',
        label: wallet.label ?? '',
    };
    isDialogOpen.value = true;
};

const saveWallet = async () => {
    if (savingWallet.value) return;
    formErrors.value = [];

    if (!editingWallet.value && !form.value.pin.trim()) {
        formErrors.value = ['PIN is required.'];
        toast.error(formErrors.value[0]);
        return;
    }
    if (form.value.method === 'rocket' && !/^\d{12}$/.test(form.value.wallet_number.trim())) {
        formErrors.value = ['Rocket wallet numbers must be exactly 12 digits.'];
        toast.error(formErrors.value[0]);
        return;
    }

    savingWallet.value = true;
    try {
        const data: any = {
            method: form.value.method,
            wallet_number: form.value.wallet_number.trim(),
            label: form.value.label.trim() || null,
        };
        if (form.value.pin) {
            data.pin = form.value.pin;
        }

        if (editingWallet.value) {
            await axios.put(`/api/wallets/${editingWallet.value.id}`, data);
            toast.success('Wallet updated.');
        } else {
            await axios.post('/api/wallets', data);
            toast.success('Wallet added.');
        }
        isDialogOpen.value = false;
        fetchWallets();
    } catch (error: any) {
        const errors = error?.response?.data?.errors;
        if (errors) {
            formErrors.value = Object.values(errors).flat() as string[];
        } else {
            formErrors.value = [error?.response?.data?.message ?? 'Failed to save wallet.'];
        }
        toast.error(formErrors.value[0] ?? 'Failed to save wallet.');
    } finally {
        savingWallet.value = false;
    }
};

const deleteWallet = async (id: number) => {
    if (!confirm('Remove this wallet?')) return;
    try {
        await axios.delete(`/api/wallets/${id}`);
        delete revealedPins.value[id];
        fetchWallets();
        toast.success('Wallet removed.');
    } catch {
        toast.error('Failed to remove wallet.');
    }
};

const togglePin = async (wallet: WalletEntry) => {
    if (revealedPins.value[wallet.id] !== undefined) {
        delete revealedPins.value[wallet.id];
        return;
    }
    try {
        const { data } = await axios.get(`/api/wallets/${wallet.id}`);
        revealedPins.value[wallet.id] = data.pin ?? '';
    } catch {
        toast.error('Failed to load PIN.');
    }
};

const formatDate = (date: string) => new Date(date).toLocaleDateString();

onMounted(() => {
    fetchWallets();
});

const breadcrumbs = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Wallet', href: '/wallet' },
];
</script>

<template>

    <Head title="Wallet" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full w-full max-w-4xl flex-1 flex-col gap-6 p-6">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-400 to-emerald-600 shadow-sm shadow-emerald-500/30">
                        <WalletIcon class="h-5 w-5 text-white" />
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Wallet</h2>
                        <p class="text-muted-foreground mt-0.5 text-sm">Your Rocket / Nagad accounts — private to you only.</p>
                    </div>
                </div>
                <Button @click="openCreateDialog">
                    <Plus class="mr-2 h-4 w-4" />
                    Add Wallet
                </Button>
            </div>

            <div class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm overflow-hidden">
                <div class="overflow-x-auto">
                <Table class="border-b">
                    <TableHeader class="bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm border-b border-zinc-200/60 dark:border-zinc-700/60">
                        <TableRow>
                            <TableHead class="px-3 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Method</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Number</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">PIN</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Label</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Added</TableHead>
                            <TableHead class="pl-2 pr-3 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest border-l w-[3.75rem]">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-if="loading" v-for="i in 2" :key="i">
                            <TableCell v-for="j in 6" :key="j" :class="{ 'border-l': j === 6 }">
                                <div class="h-5 w-full animate-pulse rounded bg-muted"></div>
                            </TableCell>
                        </TableRow>

                        <TableRow v-else-if="wallets.length === 0" class="h-24 text-center">
                            <TableCell colspan="6" class="text-muted-foreground">
                                No wallets added yet.
                            </TableCell>
                        </TableRow>

                        <TableRow v-else v-for="wallet in wallets" :key="wallet.id"
                            class="hover:bg-zinc-50 dark:hover:bg-zinc-900/60 transition-colors">
                            <TableCell class="px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <div class="p-1.5 rounded"
                                        :class="wallet.method === 'rocket' ? 'bg-purple-100 dark:bg-purple-900/20' : 'bg-orange-100 dark:bg-orange-900/20'">
                                        <Smartphone class="h-3.5 w-3.5"
                                            :class="wallet.method === 'rocket' ? 'text-purple-600 dark:text-purple-400' : 'text-orange-600 dark:text-orange-400'" />
                                    </div>
                                    <span class="text-[11px] font-semibold">{{ methodLabel(wallet.method) }}</span>
                                </div>
                            </TableCell>
                            <TableCell class="px-2 py-2 text-[11px] font-mono tabular-nums">{{ wallet.wallet_number }}</TableCell>
                            <TableCell class="px-2 py-2">
                                <div class="flex items-center gap-1.5">
                                    <code class="px-1.5 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 text-[10px] font-mono">
                                        {{ revealedPins[wallet.id] !== undefined ? revealedPins[wallet.id] : '••••' }}
                                    </code>
                                    <button @click="togglePin(wallet)" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                        <component :is="revealedPins[wallet.id] !== undefined ? EyeOff : Eye" class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </TableCell>
                            <TableCell class="px-2 py-2 text-[11px] text-zinc-500">
                                <span v-if="wallet.label">{{ wallet.label }}</span>
                                <span v-else class="text-zinc-400">—</span>
                            </TableCell>
                            <TableCell class="px-2 py-2 text-[10px] text-zinc-400">{{ formatDate(wallet.created_at) }}</TableCell>
                            <TableCell class="pl-2 pr-3 py-2 text-center border-l">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button variant="ghost" size="icon" class="h-8 w-8">
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" class="w-40">
                                        <DropdownMenuLabel>Manage</DropdownMenuLabel>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem @click="openEditDialog(wallet)">
                                            <Edit2 class="mr-2 h-4 w-4" /> Edit
                                        </DropdownMenuItem>
                                        <DropdownMenuItem @click="deleteWallet(wallet.id)" class="text-destructive">
                                            <Trash2 class="mr-2 h-4 w-4" /> Remove
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                </div>
            </div>

            <!-- Wallet Dialog -->
            <Dialog v-model:open="isDialogOpen">
                <DialogContent class="sm:max-w-[25rem] p-0 flex flex-col">
                    <DialogHeader class="p-3 border-b border-zinc-200 dark:border-zinc-800">
                        <DialogTitle class="text-[14px] font-semibold">{{ editingWallet ? 'Update Wallet' : 'Add Wallet' }}</DialogTitle>
                        <DialogDescription class="text-[11px]">Stored privately — only you can see this wallet's number and PIN.</DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-1.5 py-3 px-3">
                        <div v-if="formErrors.length" class="rounded border border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40 p-2 text-[10px] text-red-700 dark:text-red-400">
                            <p v-for="(err, i) in formErrors" :key="i">{{ err }}</p>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="method" class="text-[11px] font-semibold">Method</Label>
                            <select
                                id="method"
                                v-model="form.method"
                                class="h-8 w-full rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-2 text-[11px]"
                            >
                                <option v-for="opt in METHOD_OPTIONS" :key="opt.value" :value="opt.value" :disabled="!opt.supported">
                                    {{ opt.label }}{{ opt.supported ? '' : ' — not available yet' }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="wallet_number" class="text-[11px] font-semibold">Wallet Number</Label>
                            <Input id="wallet_number" v-model="form.wallet_number"
                                :maxlength="form.method === 'rocket' ? 12 : 20"
                                placeholder="e.g. 017XXXXXXXX" class="h-8 text-[11px]" />
                            <p v-if="form.method === 'rocket'" class="text-[9px] text-zinc-400">Rocket wallet numbers are exactly 12 digits.</p>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="pin" class="text-[11px] font-semibold">PIN</Label>
                            <div class="relative">
                                <Input id="pin" :type="showFormPin ? 'text' : 'password'" v-model="form.pin" autocomplete="new-password"
                                    :placeholder="editingWallet ? 'Leave blank to keep current PIN' : 'Enter PIN'" class="h-8 text-[11px] pr-8" />
                                <button type="button" tabindex="-1" @click="showFormPin = !showFormPin"
                                    class="absolute inset-y-0 right-0 flex items-center px-2 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                    <EyeOff v-if="showFormPin" class="h-3.5 w-3.5" />
                                    <Eye v-else class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="label" class="text-[11px] font-semibold">Label <span class="text-zinc-400 font-normal">(optional)</span></Label>
                            <Input id="label" v-model="form.label" placeholder="e.g. Personal, Backup" class="h-8 text-[11px]" />
                        </div>
                    </div>
                    <div class="p-3 border-t border-zinc-200 dark:border-zinc-800 flex gap-2">
                        <Button variant="outline" @click="isDialogOpen = false" class="h-8 text-[11px]">Cancel</Button>
                        <Button @click="saveWallet" :disabled="savingWallet" class="h-8 text-[11px] bg-emerald-600 hover:bg-emerald-700 text-white">{{ editingWallet ? 'Save Changes' : 'Add Wallet' }}</Button>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
