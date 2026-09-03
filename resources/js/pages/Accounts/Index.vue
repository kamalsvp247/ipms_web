<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';

const toast = useToast();
import axios from 'axios';
import { Search, Plus, MoreVertical, Edit2, Trash2, Eye, EyeOff, CircleUser, ChevronDown, ChevronUp, Users, SlidersHorizontal, CalendarRange, FileText, ToggleLeft, Power, RotateCcw, Zap, Loader2, CreditCard, ExternalLink, Lock } from 'lucide-vue-next';
import { ref, onMounted, onBeforeUnmount, computed, watch } from 'vue';
import { useToast } from 'vue-toastification';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import DataTablePagination from '@/components/DataTablePagination.vue';
import DateRangePicker from '@/components/DateRangePicker.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import Switch from '@/components/ui/Switch.vue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useAccountLock } from '@/composables/useAccountLock';

interface AccountPdf {
    id?: number;
    name: string;
    // The IVAC web file number printed under the photo barcode (e.g. BGDDVBA47626), read off the
    // form at upload. Null when the file is not a readable application form.
    application_id?: string | null;
    // The list query delivers PDF rows without their payload — only the edit modal / PDF viewer,
    // which fetch a single account, carry `base64`.
    base64: string;
    is_primary: boolean;
    // Viewer-only — the Ghostscript-optimized copy the bot actually uploads to IVAC (~80% smaller).
    // Never sent back on save; syncPdfs() re-derives it from `base64` every time.
    optimized_base64?: string | null;
    original_size?: number | null;
    optimized_size?: number | null;
    // Set only on freshly attached files, from the attach-time age check. Display only.
    web_registration_date?: string | null;
}

const BOOKING_CITIES = ['Dhaka', 'Khulna', 'Chittagong', 'Rajshahi', 'Sylhet'] as const;

// Mirrors App\Support\AutoPaymentMethods. Rocket only for now — Nagad and bKash are listed but
// disabled, so selecting either would queue a payment that fails. They stay in the list so an
// account already saved with one still renders its label.
const AUTO_PAYMENT_METHODS = [
    { value: 'rocket', label: 'Rocket', supported: true },
    { value: 'nagad', label: 'Nagad', supported: false },
    { value: 'bkash', label: 'bKash', supported: false },
] as const;

interface Account {
    id: number;
    phone: string;
    email?: string;
    tag?: string;
    appointment_id?: string;
    appointment_id_updated_at?: string | null;
    appointment_dates?: string[] | null;
    pdfs?: AccountPdf[] | null;
    pdfs_count?: number;
    pdf_uploaded?: boolean;
    booking_configured?: boolean;
    booking_city?: string | null;
    auto_payment?: boolean;
    auto_payment_method?: string | null;
    auto_payment_wallet?: string | null;
    auto_payment_paid?: boolean;
    password?: string;
    is_active: boolean;
    single_sign_in: boolean;
    status: 'running' | 'completed' | 'cancelled';
    agent_slot_id: number | null;
    agentSlot?: { id: number; name: string } | null;
    user?: { id: number; name: string };
    max_retries?: number;
    retry_delay_ms?: number;
    signin_tick_shots?: number;
    signin_tick_interval_ms?: number;
    otp_tick_shots?: number;
    otp_tick_interval_ms?: number;
    slot_tick_shots?: number;
    slot_tick_interval_ms?: number;
    payment_tick_shots?: number;
    payment_tick_interval_ms?: number;
    created_at: string;
    updated_at: string;
}

interface UserOption {
    id: number;
    name: string;
    email: string;
}

const page = usePage();
const isSuperAdmin = computed(() => page.props.auth.permissions?.['bot.manage'] ?? false);
// Race behavior tuning (ticks, single sign-in, retry) is manager/admin territory — agents get the defaults only.
const canManageRaceSettings = computed(() => (page.props.auth.permissions as any)?.['accounts.assign'] ?? false);
// Agents don't need appointment dates, run status, or the booking-config gate surfaced — those columns are hidden for them.
const isAgent = computed(() => (page.props.auth?.user as { role?: string } | undefined)?.role === 'agent');
// Table column visibility: managers see exactly what agents see. The operational columns (dates,
// status, active, booking config) and the race-tuning ones (single sign-in, tick tuning) are admin
// only. This governs the table and the mobile cards; the edit modal's fields are unaffected.
const showAdminColumns = computed(() => isSuperAdmin.value);
// Daily account lock window: agents cannot edit or delete inside it, managers and admins can. The
// server enforces this (AccountPolicy / AccountController); these guards only keep the UI honest.
const { accountsLocked, lockRangeLabel, LOCK_MESSAGE } = useAccountLock();

const blockedByLock = (): boolean => {
    if (!accountsLocked.value) return false;
    toast.error(LOCK_MESSAGE);
    return true;
};
const userOptions = ref<UserOption[]>([]);

const accounts = ref<Account[]>([]);
const paymentLinkUrls = ref<Record<string, string>>({});
const paymentLinkPhones = computed(() => new Set(Object.keys(paymentLinkUrls.value)));
const invoicesByPhone = ref<Record<string, { txrId: string; archived: boolean }>>({});
const downloadingInvoicePhone = ref<string | null>(null);
const paginationMeta = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    from: 0,
    to: 0,
});
const loading = ref(true);
const isDialogOpen = ref(false);
const editingAccount = ref<Account | null>(null);
const pdfsLoading = ref(false);
const savingAccount = ref(false);
const searchQuery = ref('');
const showPasswords = ref(false);
const showAutoPaymentPin = ref(false);
const showQuickWalletPin = ref(false);
// +1 each for the Invoice and Live State columns, which every role sees.
const desktopColumnCount = computed(() => (showPasswords.value ? 17 : 16) - (showAdminColumns.value ? 0 : 6));
const statusFilter = ref<'all' | 'running' | 'completed' | 'cancelled'>('running');
const formErrors = ref<string[]>([]);

const groupByAgent = ref(true);

interface AccountGroup {
    key: string;
    label: string;
    items: { account: Account; idx: number }[];
}

// `idx` is the row's position as displayed (S/N + zebra striping read it), not its position in the
// raw fetch order — grouping must not make the S/N column look shuffled.
const groupedAccounts = computed<AccountGroup[]>(() => {
    if (!groupByAgent.value) {
        return [{ key: '', label: '', items: accounts.value.map((account, idx) => ({ account, idx })) }];
    }
    const groups = new Map<string, Account[]>();
    for (const account of accounts.value) {
        const key = account.user?.name || 'Unassigned';
        if (!groups.has(key)) {
            groups.set(key, []);
        }
        groups.get(key)!.push(account);
    }
    let position = 0;
    return Array.from(groups.entries())
        .sort((a, b) => a[0].localeCompare(b[0]))
        .map(([key, items]) => ({
            key,
            label: key,
            items: items.map((account) => ({ account, idx: position++ })),
        }));
});

// IVAC's Bangladesh weekend is Friday + Saturday — disables those days in the
// date-range picker and is used to compute the default "Apply to All" range.
const isWeekendDate = (dateStr: string): boolean => {
    const day = new Date(dateStr + 'T00:00:00Z').getUTCDay();
    return day === 5 || day === 6;
};

const toDateStr = (date: Date): string => date.toISOString().slice(0, 10);

// The working week (Sun–Thu) is 5 contiguous calendar days with no weekend inside
// it, so a straight 5-day span only stays weekend-free when it starts on a Sunday.
// Take this week's Sun–Thu, drop today and anything before it, and if nothing is
// left (today is Thu, or today is already Fri/Sat) roll forward to next week.
const computeDefaultAppointmentRange = (): { from: string; to: string } => {
    const now = new Date();
    const today = new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()));
    const thisWeekSunday = new Date(today);
    thisWeekSunday.setUTCDate(thisWeekSunday.getUTCDate() - thisWeekSunday.getUTCDay());

    const workdaysFrom = (sunday: Date): Date[] =>
        Array.from({ length: 5 }, (_, i) => {
            const d = new Date(sunday);
            d.setUTCDate(d.getUTCDate() + i);
            return d;
        });

    let workdays = workdaysFrom(thisWeekSunday).filter((d) => d > today);
    if (workdays.length === 0) {
        const nextSunday = new Date(thisWeekSunday);
        nextSunday.setUTCDate(nextSunday.getUTCDate() + 7);
        workdays = workdaysFrom(nextSunday);
    }
    return { from: toDateStr(workdays[0]), to: toDateStr(workdays[workdays.length - 1]) };
};

const DEFAULT_TICK_SHOTS = 1;
const DEFAULT_TICK_INTERVAL_MS = 21000;

const bulkApplyInputs = ref({
    signin_tick_shots: DEFAULT_TICK_SHOTS as number | null,
    signin_tick_interval_ms: DEFAULT_TICK_INTERVAL_MS as number | null,
    otp_tick_shots: DEFAULT_TICK_SHOTS as number | null,
    otp_tick_interval_ms: DEFAULT_TICK_INTERVAL_MS as number | null,
    slot_tick_shots: DEFAULT_TICK_SHOTS as number | null,
    slot_tick_interval_ms: DEFAULT_TICK_INTERVAL_MS as number | null,
    payment_tick_shots: DEFAULT_TICK_SHOTS as number | null,
    payment_tick_interval_ms: DEFAULT_TICK_INTERVAL_MS as number | null,
});

const globalDefaults = ref({
    max_retries: 2,
    retry_delay_ms: 2000,
});

const fetchGlobalDefaults = async () => {
    try {
        const response = await axios.get('/api/settings');
        const settings = response.data;
        globalDefaults.value = {
            max_retries: settings.max_retries ?? 2,
            retry_delay_ms: settings.sign_in_retry_delay_ms ?? 2000,
        };
    } catch (error) {
        console.error('Failed to fetch global defaults', error);
    }
};

const form = ref({
    phone: '',
    email: '',
    tag: '',
    appointment_id: '',
    appointment_from: computeDefaultAppointmentRange().from,
    appointment_to: computeDefaultAppointmentRange().to,
    password: '',
    is_active: true,
    single_sign_in: true,
    pdfs: [] as AccountPdf[],
    booking_city: 'Dhaka' as string,
    auto_payment: false,
    auto_payment_method: 'rocket' as string,
    auto_payment_wallet: '',
    auto_payment_pin: '',
    max_retries: 2,
    retry_delay_ms: 2000,
    signin_tick_shots: 1,
    signin_tick_interval_ms: 21000,
    otp_tick_shots: 1,
    otp_tick_interval_ms: 21000,
    slot_tick_shots: 1,
    slot_tick_interval_ms: 21000,
    payment_tick_shots: 1,
    payment_tick_interval_ms: 21000,
});

const PER_PAGE = 500;

const fetchAccounts = async (page = 1) => {
    loading.value = true;
    try {
        const params: any = {
            page,
            search: searchQuery.value,
            per_page: PER_PAGE,
        };
        if (statusFilter.value !== 'all') {
            params.status = statusFilter.value;
        }
        const response = await axios.get('/api/accounts', { params });
        accounts.value = response.data.data;
        accounts.value.forEach(initTuningRow);
        paginationMeta.value = {
            current_page: response.data.current_page,
            last_page: response.data.last_page,
            total: response.data.total,
            from: response.data.from,
            to: response.data.to,
        };
    } catch (error) {
        console.error('Failed to fetch accounts', error);
    } finally {
        loading.value = false;
    }
};

let searchTimeout: any;
const onSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchAccounts(1);
    }, 500);
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString() + ' ' + new Date(date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

// Compact "1×21s" style label for the collapsed Tuning column — falls back to raw ms when the
// interval doesn't divide evenly into seconds.
const fmtInterval = (ms: number): string => (ms % 1000 === 0 ? `${ms / 1000}s` : `${ms}ms`);

const openCreateDialog = () => {
    editingAccount.value = null;
    formErrors.value = [];
    showAutoPaymentPin.value = false;
    const defaultRange = computeDefaultAppointmentRange();
    form.value = {
        phone: '',
        email: '',
        tag: '',
        appointment_id: '',
        appointment_from: defaultRange.from,
        appointment_to: defaultRange.to,
        password: '',
        is_active: true,
        single_sign_in: true,
        pdfs: [] as AccountPdf[],
        booking_city: 'Dhaka' as string,
        auto_payment: false,
        auto_payment_method: 'rocket' as string,
        auto_payment_wallet: '',
        auto_payment_pin: '',
        max_retries: globalDefaults.value.max_retries,
        retry_delay_ms: globalDefaults.value.retry_delay_ms,
        signin_tick_shots: 1,
        signin_tick_interval_ms: 21000,
        otp_tick_shots: 1,
        otp_tick_interval_ms: 21000,
        slot_tick_shots: 1,
        slot_tick_interval_ms: 21000,
        payment_tick_shots: 1,
        payment_tick_interval_ms: 21000,
    };
    selectedWalletId.value = '';
    fetchWalletOptions();
    isDialogOpen.value = true;
};

const openEditDialog = async (account: Account) => {
    if (blockedByLock()) return;
    editingAccount.value = account;
    formErrors.value = [];
    showAutoPaymentPin.value = false;
    selectedWalletId.value = '';
    fetchWalletOptions();

    form.value = {
        phone: account.phone,
        email: account.email ?? '',
        tag: account.tag ?? '',
        appointment_id: account.appointment_id ?? '',
        appointment_from: account.appointment_dates?.[0] ?? '',
        appointment_to: account.appointment_dates?.[(account.appointment_dates?.length ?? 0) - 1] ?? '',
        password: account.password ?? '',
        is_active: account.is_active,
        single_sign_in: account.single_sign_in ?? false,
        pdfs: [] as AccountPdf[],
        booking_city: account.booking_city ?? 'Dhaka',
        auto_payment: account.auto_payment ?? false,
        auto_payment_method: account.auto_payment_method ?? 'rocket',
        auto_payment_wallet: account.auto_payment_wallet ?? '',
        // Left blank deliberately: an empty PIN on save means "keep the stored one". The real
        // value arrives from show() below so the field can be pre-filled once it loads.
        auto_payment_pin: '',
        max_retries: account.max_retries ?? globalDefaults.value.max_retries,
        retry_delay_ms: account.retry_delay_ms ?? globalDefaults.value.retry_delay_ms,
        signin_tick_shots: account.signin_tick_shots ?? 10,
        signin_tick_interval_ms: account.signin_tick_interval_ms ?? 1000,
        otp_tick_shots: account.otp_tick_shots ?? 10,
        otp_tick_interval_ms: account.otp_tick_interval_ms ?? 1000,
        slot_tick_shots: account.slot_tick_shots ?? 10,
        slot_tick_interval_ms: account.slot_tick_interval_ms ?? 1000,
        payment_tick_shots: account.payment_tick_shots ?? 10,
        payment_tick_interval_ms: account.payment_tick_interval_ms ?? 1000,
    };
    isDialogOpen.value = true;

    // PDFs (Base64) are omitted from the list payload for weight — fetch the full set on demand
    // so the edit form round-trips them. Save is blocked until this resolves to avoid an empty
    // `pdfs` array silently wiping the account's uploaded documents.
    pdfsLoading.value = true;
    try {
        const { data } = await axios.get(`/api/accounts/${account.id}`);
        if (editingAccount.value?.id === account.id) {
            form.value.pdfs = (data.pdfs ?? []).map((p: AccountPdf) => ({ ...p }));
            // The PIN is kept out of the list payload and only served here.
            form.value.auto_payment_pin = data.auto_payment_pin ?? '';
        }
    } catch {
        toast.error('Failed to load account PDFs.');
    } finally {
        pdfsLoading.value = false;
    }
};

const pdfInput = ref<HTMLInputElement | null>(null);

const readFileAsBase64 = (file: File): Promise<string> =>
    new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            const result = String(reader.result);
            const comma = result.indexOf(',');
            resolve(comma >= 0 ? result.slice(comma + 1) : result);
        };
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(file);
    });

const pdfChecking = ref(false);

/**
 * Reads the form's printed web registration date server-side and refuses anything past the age
 * limit. Advisory only — the save call re-checks it — so a failing request still lets the file be
 * attached rather than blocking the user on a transient error.
 */
const checkPdfAge = async (name: string, base64: string): Promise<{ accepted: boolean; date: string | null }> => {
    try {
        const { data } = await axios.post('/api/accounts/pdfs/inspect', { name, base64 });
        if (data.expired) {
            toast.error(
                `${name} was web-registered on ${data.web_registration_date} (${data.age_days} days ago). ` +
                `Application forms older than ${data.max_age_days} days cannot be uploaded.`,
            );
            return { accepted: false, date: data.web_registration_date };
        }
        if (!data.readable) {
            toast.warning(`${name}: no web registration date found — attached without the age check.`);
        }
        return { accepted: true, date: data.web_registration_date ?? null };
    } catch {
        return { accepted: true, date: null };
    }
};

const onPdfFilesSelected = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    pdfChecking.value = true;
    for (const file of files) {
        try {
            const base64 = await readFileAsBase64(file);
            const check = await checkPdfAge(file.name, base64);
            if (!check.accepted) continue;
            form.value.pdfs.push({
                name: file.name,
                base64,
                is_primary: form.value.pdfs.length === 0,
                web_registration_date: check.date,
            });
        } catch {
            toast.error(`Failed to read ${file.name}`);
        }
    }
    pdfChecking.value = false;
    // Guarantee exactly one primary after adding.
    if (form.value.pdfs.length > 0 && !form.value.pdfs.some((p) => p.is_primary)) {
        form.value.pdfs[0].is_primary = true;
    }
    input.value = '';
};

const setPrimaryPdf = (index: number) => {
    form.value.pdfs.forEach((p, i) => {
        p.is_primary = i === index;
    });
};

const removePdf = (index: number) => {
    const wasPrimary = form.value.pdfs[index]?.is_primary;
    form.value.pdfs.splice(index, 1);
    if (wasPrimary && form.value.pdfs.length > 0) {
        form.value.pdfs[0].is_primary = true;
    }
};

const saveAccount = async () => {
    if (editingAccount.value && blockedByLock()) return;
    if (editingAccount.value && pdfsLoading.value) {
        toast.error('PDFs are still loading — please wait.');
        return;
    }
    if (savingAccount.value) return;
    formErrors.value = [];

    // Auto payment spends real money unattended, so the credential set must be complete before
    // the account can be armed. Caught here as well as server-side to keep the message immediate.
    if (form.value.auto_payment) {
        const missing: string[] = [];
        if (!form.value.auto_payment_method) missing.push('payment method');
        if (!form.value.auto_payment_wallet.trim()) missing.push('wallet number');
        if (!form.value.auto_payment_pin.trim()) missing.push('PIN');
        if (missing.length > 0) {
            formErrors.value = [`Auto payment needs a ${missing.join(', ')}.`];
            toast.error(formErrors.value[0]);
            return;
        }
        if (form.value.auto_payment_method === 'rocket' && !/^\d{12}$/.test(form.value.auto_payment_wallet.trim())) {
            formErrors.value = ['Rocket wallet numbers must be exactly 12 digits.'];
            toast.error(formErrors.value[0]);
            return;
        }
    }

    savingAccount.value = true;
    try {
        const data: any = {
            phone: form.value.phone,
            email: form.value.email || null,
            tag: form.value.tag || null,
            pdfs: form.value.pdfs,
            booking_city: form.value.booking_city || null,
            auto_payment: form.value.auto_payment,
            auto_payment_method: form.value.auto_payment ? form.value.auto_payment_method : null,
            auto_payment_wallet: form.value.auto_payment ? form.value.auto_payment_wallet.trim() : null,
            is_active: form.value.is_active,
            single_sign_in: form.value.single_sign_in,
            max_retries: form.value.max_retries,
            retry_delay_ms: form.value.retry_delay_ms,
            signin_tick_shots: form.value.signin_tick_shots,
            signin_tick_interval_ms: form.value.signin_tick_interval_ms,
            otp_tick_shots: form.value.otp_tick_shots,
            otp_tick_interval_ms: form.value.otp_tick_interval_ms,
            slot_tick_shots: form.value.slot_tick_shots,
            slot_tick_interval_ms: form.value.slot_tick_interval_ms,
            payment_tick_shots: form.value.payment_tick_shots,
            payment_tick_interval_ms: form.value.payment_tick_interval_ms,
        };

        // Agents never see the Appointment section, so its keys are omitted entirely rather than
        // sent from an unrendered form — an agent's save leaves the stored values untouched.
        if (!isAgent.value) {
            data.appointment_id = form.value.appointment_id || null;
            data.appointment_dates = expandDateRange(form.value.appointment_from, form.value.appointment_to);
        }

        if (form.value.password) {
            data.password = form.value.password;
        }

        // Same contract as password: send the PIN only when the field holds something, so an
        // untouched form never blanks the stored credential.
        if (form.value.auto_payment_pin) {
            data.auto_payment_pin = form.value.auto_payment_pin;
        }

        if (editingAccount.value) {
            await axios.put(`/api/accounts/${editingAccount.value.id}`, data);
            toast.success('Account updated successfully.');
        } else {
            data.password = form.value.password;
            await axios.post('/api/accounts', data);
            toast.success('Account created successfully.');
        }
        isDialogOpen.value = false;
        fetchAccounts();
    } catch (error: any) {
        const errors = error?.response?.data?.errors;
        if (errors) {
            formErrors.value = Object.values(errors).flat() as string[];
        } else {
            formErrors.value = [error?.response?.data?.message ?? 'Failed to save account.'];
        }
        toast.error(formErrors.value[0] ?? 'Failed to save account.');
    } finally {
        savingAccount.value = false;
    }
};

const updateStatus = async (account: Account, active: boolean) => {
    if (blockedByLock()) return;
    if (account.is_active === active) return;
    try {
        await axios.put(`/api/accounts/${account.id}`, { is_active: active });
        fetchAccounts();
        toast.success(`Account marked as ${active ? 'active' : 'inactive'}.`);
    } catch (error) {
        toast.error('Failed to update account status.');
    }
};

const updateSingleSignIn = async (account: Account, enabled: boolean) => {
    if (blockedByLock()) return;
    if (account.single_sign_in === enabled) return;
    const previous = account.single_sign_in;
    account.single_sign_in = enabled;
    try {
        await axios.put(`/api/accounts/${account.id}`, { single_sign_in: enabled });
        toast.success(`Single sign-in ${enabled ? 'enabled' : 'disabled'}.`);
    } catch (error) {
        account.single_sign_in = previous;
        toast.error('Failed to update single sign-in.');
    }
};

// Manual overrides for the two slot-reserve setup gates — lets an operator mark setup done
// (or force a re-run) without waiting for the bot, e.g. after fixing it out-of-band.
const updatePdfUploaded = async (account: Account, uploaded: boolean) => {
    if (blockedByLock()) return;
    if (account.pdf_uploaded === uploaded) return;
    const previous = account.pdf_uploaded;
    account.pdf_uploaded = uploaded;
    try {
        await axios.put(`/api/accounts/${account.id}`, { pdf_uploaded: uploaded });
        toast.success(`PDF upload marked ${uploaded ? 'done' : 'pending'}.`);
    } catch (error) {
        account.pdf_uploaded = previous;
        toast.error('Failed to update PDF upload status.');
    }
};

const updateBookingConfigured = async (account: Account, configured: boolean) => {
    if (blockedByLock()) return;
    if (account.booking_configured === configured) return;
    const previous = account.booking_configured;
    account.booking_configured = configured;
    try {
        await axios.put(`/api/accounts/${account.id}`, { booking_configured: configured });
        toast.success(`Booking config marked ${configured ? 'done' : 'pending'}.`);
    } catch (error) {
        account.booking_configured = previous;
        toast.error('Failed to update booking config status.');
    }
};

// Quick on/off from the table. Turning off clears method/wallet (kept in sync locally so a later
// re-enable doesn't silently resend stale values); the PIN column is left alone server-side.
// Turning on re-sends the already-stored method/wallet when both are present. If either is
// missing — or the PIN never got set even though method/wallet look configured — the picker
// dialog opens instead of a dead-end error toast.
const updateAutoPayment = async (account: Account, enabled: boolean) => {
    if (blockedByLock()) return;
    if (account.auto_payment === enabled) return;

    if (!enabled) {
        const previous = account.auto_payment;
        account.auto_payment = false;
        try {
            await axios.put(`/api/accounts/${account.id}`, {
                auto_payment: false,
                auto_payment_method: null,
                auto_payment_wallet: null,
            });
            account.auto_payment_method = null;
            account.auto_payment_wallet = null;
            toast.success('Auto payment disabled.');
        } catch {
            account.auto_payment = previous;
            toast.error('Failed to update auto payment.');
        }
        return;
    }

    if (account.auto_payment_method && account.auto_payment_wallet) {
        const previous = account.auto_payment;
        account.auto_payment = true;
        try {
            await axios.put(`/api/accounts/${account.id}`, {
                auto_payment: true,
                auto_payment_method: account.auto_payment_method,
                auto_payment_wallet: account.auto_payment_wallet,
            });
            toast.success('Auto payment enabled.');
        } catch (error: any) {
            account.auto_payment = previous;
            const errors = error?.response?.data?.errors;
            if (error?.response?.status === 422 && errors?.auto_payment_pin) {
                openAutoPaymentDialog(account);
            } else {
                toast.error('Failed to update auto payment.');
            }
        }
        return;
    }

    openAutoPaymentDialog(account);
};

// Auto payment refuses an account that already has a completed payment, since IVAC keeps reissuing
// checkout links and paying another would charge the wallet twice for one booking. Re-arming says
// this is a genuinely new booking.
const rearmAutoPayment = async (account: Account) => {
    if (blockedByLock()) return;
    const previous = account.auto_payment_paid;
    account.auto_payment_paid = false;
    try {
        await axios.post(`/api/accounts/${account.id}/rearm-auto-payment`);
        toast.success('Auto payment re-armed for this account.');
    } catch {
        account.auto_payment_paid = previous;
        toast.error('Failed to re-arm auto payment.');
    }
};

const rearmAllAutoPayment = async () => {
    if (blockedByLock()) return;
    const ids = accounts.value.map((a) => a.id);
    if (!ids.length) return;
    try {
        const { data } = await axios.put('/api/accounts/bulk-rearm-auto-payment', { account_ids: ids });
        accounts.value.forEach((a) => { a.auto_payment_paid = false; });
        toast.success(`Re-armed auto payment on ${data.updated} account(s).`);
    } catch {
        toast.error('Failed to re-arm auto payment.');
    }
};

// Table-row quick config: opens when a row's Auto Payment switch is flipped on but the account
// has no wallet credentials yet. Lets the operator pick a saved wallet (see the Wallet page) or
// type a fresh number/PIN — which also gets saved to the wallet book so it can be reused.
const autoPaymentDialogOpen = ref(false);
const autoPaymentDialogAccount = ref<Account | null>(null);
const autoPaymentSelectedWalletId = ref<number | 'new' | ''>('');
const autoPaymentSaving = ref(false);
const autoPaymentForm = ref({
    method: 'rocket' as string,
    wallet_number: '',
    pin: '',
    label: '',
});

const autoPaymentWalletOptionsForMethod = computed(() =>
    walletOptions.value.filter((w) => w.method === autoPaymentForm.value.method)
);

const openAutoPaymentDialog = (account: Account) => {
    autoPaymentDialogAccount.value = account;
    autoPaymentForm.value = {
        method: account.auto_payment_method ?? 'rocket',
        wallet_number: account.auto_payment_wallet ?? '',
        pin: '',
        label: '',
    };
    autoPaymentSelectedWalletId.value = '';
    showQuickWalletPin.value = false;
    fetchWalletOptions();
    autoPaymentDialogOpen.value = true;
};

const onSelectAutoPaymentWallet = async (value: string) => {
    if (value === 'new') {
        autoPaymentSelectedWalletId.value = 'new';
        autoPaymentForm.value.label = '';
        return;
    }
    if (value === '') {
        autoPaymentSelectedWalletId.value = '';
        return;
    }
    const id = Number(value);
    autoPaymentSelectedWalletId.value = id;
    const found = walletOptions.value.find((w) => w.id === id);
    if (found) {
        autoPaymentForm.value.wallet_number = found.wallet_number;
    }
    try {
        const { data } = await axios.get(`/api/wallets/${id}`);
        autoPaymentForm.value.pin = data.pin ?? '';
    } catch {
        toast.error('Failed to load the saved wallet PIN.');
    }
};

watch(() => autoPaymentForm.value.method, () => {
    autoPaymentSelectedWalletId.value = '';
});

const confirmAutoPaymentDialog = async () => {
    if (blockedByLock()) return;
    const account = autoPaymentDialogAccount.value;
    if (!account || autoPaymentSaving.value) return;

    const number = autoPaymentForm.value.wallet_number.trim();
    const pin = autoPaymentForm.value.pin.trim();
    if (!number || !pin) {
        toast.error('Enter a wallet number and PIN.');
        return;
    }
    if (autoPaymentForm.value.method === 'rocket' && !/^\d{12}$/.test(number)) {
        toast.error('Rocket wallet numbers must be exactly 12 digits.');
        return;
    }

    autoPaymentSaving.value = true;
    try {
        // A freshly typed number (not picked from the list) is also saved to the wallet book,
        // unless it already matches one — so re-enabling the same account twice doesn't duplicate it.
        const isFreshEntry = autoPaymentSelectedWalletId.value === '' || autoPaymentSelectedWalletId.value === 'new';
        const alreadySaved = walletOptions.value.some(
            (w) => w.method === autoPaymentForm.value.method && w.wallet_number === number
        );
        if (isFreshEntry && !alreadySaved) {
            try {
                const { data } = await axios.post('/api/wallets', {
                    method: autoPaymentForm.value.method,
                    wallet_number: number,
                    pin,
                    label: autoPaymentForm.value.label.trim() || null,
                });
                walletOptions.value.push(data);
            } catch {
                // Non-fatal — the wallet book entry is a convenience; the account still gets configured below.
            }
        }

        await axios.put(`/api/accounts/${account.id}`, {
            auto_payment: true,
            auto_payment_method: autoPaymentForm.value.method,
            auto_payment_wallet: number,
            auto_payment_pin: pin,
        });
        account.auto_payment = true;
        account.auto_payment_method = autoPaymentForm.value.method;
        account.auto_payment_wallet = number;
        toast.success('Auto payment enabled.');
        autoPaymentDialogOpen.value = false;
    } catch (error: any) {
        const errors = error?.response?.data?.errors;
        const msg = errors ? (Object.values(errors).flat()[0] as string) : (error?.response?.data?.message ?? 'Failed to enable auto payment.');
        toast.error(msg);
    } finally {
        autoPaymentSaving.value = false;
    }
};

// Batch versions of the two manual setup-gate toggles above — applied to every account currently
// listed (respects the active search/status filter), for clearing a whole backlog in one click.
const bulkSetupBusy = ref({ pdf: false, booking: false, active: false });

const bulkSetPdfUploaded = async (uploaded: boolean) => {
    if (blockedByLock()) return;
    const ids = accounts.value.map((a) => a.id);
    if (!ids.length || bulkSetupBusy.value.pdf) return;
    bulkSetupBusy.value.pdf = true;
    try {
        await axios.put('/api/accounts/bulk-setup-state', { account_ids: ids, pdf_uploaded: uploaded });
        for (const account of accounts.value) {
            account.pdf_uploaded = uploaded;
        }
        toast.success(`Attachment marked ${uploaded ? 'done' : 'pending'} for ${ids.length} account(s).`);
    } catch {
        toast.error('Failed to batch-update attachment status.');
    } finally {
        bulkSetupBusy.value.pdf = false;
    }
};

// Batch date-range apply — expands the range client-side (same helper as the create form) and
// sends the resulting date list to every account currently listed via a real bulk endpoint.
const bulkDateRange = ref(computeDefaultAppointmentRange());
const bulkDateRangeBusy = ref(false);

const applyBulkDateRange = async () => {
    if (blockedByLock()) return;
    if (!bulkDateRange.value.from || bulkDateRangeBusy.value) return;
    const ids = accounts.value.map((a) => a.id);
    if (!ids.length) return;
    const dates = expandDateRange(bulkDateRange.value.from, bulkDateRange.value.to);
    bulkDateRangeBusy.value = true;
    try {
        await axios.put('/api/accounts/bulk-appointment-dates', { account_ids: ids, appointment_dates: dates });
        for (const account of accounts.value) {
            account.appointment_dates = dates;
        }
        toast.success(`Applied ${dates.length} date(s) to ${ids.length} account(s).`);
        bulkDateRange.value = computeDefaultAppointmentRange();
    } catch {
        toast.error('Failed to batch-update appointment dates.');
    } finally {
        bulkDateRangeBusy.value = false;
    }
};

const bulkSetBookingConfigured = async (configured: boolean) => {
    if (blockedByLock()) return;
    const ids = accounts.value.map((a) => a.id);
    if (!ids.length || bulkSetupBusy.value.booking) return;
    bulkSetupBusy.value.booking = true;
    try {
        await axios.put('/api/accounts/bulk-setup-state', { account_ids: ids, booking_configured: configured });
        for (const account of accounts.value) {
            account.booking_configured = configured;
        }
        toast.success(`Booking config marked ${configured ? 'done' : 'pending'} for ${ids.length} account(s).`);
    } catch {
        toast.error('Failed to batch-update booking config status.');
    } finally {
        bulkSetupBusy.value.booking = false;
    }
};

const bulkSetActive = async (active: boolean) => {
    if (blockedByLock()) return;
    const ids = accounts.value.map((a) => a.id);
    if (!ids.length || bulkSetupBusy.value.active) return;
    bulkSetupBusy.value.active = true;
    try {
        await axios.put('/api/accounts/bulk-setup-state', { account_ids: ids, is_active: active });
        for (const account of accounts.value) {
            account.is_active = active;
        }
        toast.success(`${ids.length} account(s) marked ${active ? 'active' : 'inactive'}.`);
    } catch {
        toast.error('Failed to batch-update active status.');
    } finally {
        bulkSetupBusy.value.active = false;
    }
};

const updateAccountStatus = async (account: Account, status: 'running' | 'completed' | 'cancelled') => {
    if (blockedByLock()) return;
    if (account.status === status) return;
    try {
        await axios.put(`/api/accounts/${account.id}`, { status });
        account.status = status;
        // The server releases the worker slot as soon as an account stops running — mirror that here
        // rather than leaving the row claiming an assignment it no longer holds.
        if (status !== 'running') {
            account.agent_slot_id = null;
        }
        toast.success(`Account marked as ${status}.`);
        if (statusFilter.value !== 'all' && statusFilter.value !== status) {
            fetchAccounts();
        }
    } catch (error) {
        toast.error('Failed to update account status.');
    }
};

const deleteAccount = async (id: number) => {
    if (blockedByLock()) return;
    if (!confirm('Are you sure you want to delete this account?')) return;
    try {
        await axios.delete(`/api/accounts/${id}`);
        fetchAccounts();
        toast.success('Account deleted.');
    } catch (error) {
        toast.error('Failed to delete account.');
    }
};

const fetchUsers = async () => {
    try {
        const response = await axios.get('/api/users', { params: { per_page: 200 } });
        userOptions.value = response.data.data;
    } catch {
        // non-super-admins cannot access /api/users — silently ignore
    }
};

// The current user's saved Rocket/Nagad wallets (see the Wallet page) — offered as a dropdown in
// the Auto Payment section so the same number/PIN doesn't get retyped on every account, and a
// number typed here can be saved back into the wallet book for reuse.
interface WalletOption {
    id: number;
    method: 'rocket' | 'nagad';
    wallet_number: string;
    label?: string | null;
}

const walletOptions = ref<WalletOption[]>([]);
const selectedWalletId = ref<number | 'new' | ''>('');
const newWalletLabel = ref('');
const savingNewWallet = ref(false);

const fetchWalletOptions = async () => {
    try {
        const { data } = await axios.get('/api/wallets');
        walletOptions.value = data;
    } catch {
        // wallets are personal — an empty/failed fetch just means manual entry stays the only option
    }
};

const walletOptionsForMethod = computed(() =>
    walletOptions.value.filter((w) => w.method === form.value.auto_payment_method)
);

const onSelectWallet = async (value: string) => {
    if (value === 'new') {
        selectedWalletId.value = 'new';
        newWalletLabel.value = '';
        return;
    }
    if (value === '') {
        selectedWalletId.value = '';
        return;
    }
    const id = Number(value);
    selectedWalletId.value = id;
    const found = walletOptions.value.find((w) => w.id === id);
    if (found) {
        form.value.auto_payment_wallet = found.wallet_number;
    }
    try {
        const { data } = await axios.get(`/api/wallets/${id}`);
        form.value.auto_payment_pin = data.pin ?? '';
    } catch {
        toast.error('Failed to load the saved wallet PIN.');
    }
};

// Persists the number/PIN currently typed in the form as a new wallet entry, then selects it —
// the same row now also shows up on the Wallet page.
const saveNewWallet = async () => {
    if (savingNewWallet.value) return;
    const number = form.value.auto_payment_wallet.trim();
    const pin = form.value.auto_payment_pin.trim();
    if (!number || !pin) {
        toast.error('Enter a wallet number and PIN before saving to Wallet.');
        return;
    }
    if (form.value.auto_payment_method === 'rocket' && !/^\d{12}$/.test(number)) {
        toast.error('Rocket wallet numbers must be exactly 12 digits.');
        return;
    }
    savingNewWallet.value = true;
    try {
        const { data } = await axios.post('/api/wallets', {
            method: form.value.auto_payment_method,
            wallet_number: number,
            pin,
            label: newWalletLabel.value.trim() || null,
        });
        walletOptions.value.push(data);
        selectedWalletId.value = data.id;
        newWalletLabel.value = '';
        toast.success('Saved to your Wallet.');
    } catch (error: any) {
        toast.error(error?.response?.data?.message ?? 'Failed to save wallet.');
    } finally {
        savingNewWallet.value = false;
    }
};

watch(() => form.value.auto_payment_method, () => {
    selectedWalletId.value = '';
});

const changeOwner = async (account: Account, userId: number) => {
    if (blockedByLock()) return;
    try {
        await axios.put(`/api/accounts/${account.id}`, { user_id: userId });
        account.user = userOptions.value.find(u => u.id === userId) as any ?? account.user;
        toast.success('Account owner updated.');
    } catch (error) {
        toast.error('Failed to change account owner.');
    }
};

interface TuningRow {
    signin_tick_shots: number;
    signin_tick_interval_ms: number;
    otp_tick_shots: number;
    otp_tick_interval_ms: number;
    slot_tick_shots: number;
    slot_tick_interval_ms: number;
    payment_tick_shots: number;
    payment_tick_interval_ms: number;
    saving: boolean;
}

const tuningRows = ref<Record<number, TuningRow>>({});

const initTuningRow = (account: Account) => {
    tuningRows.value[account.id] = {
        signin_tick_shots: account.signin_tick_shots ?? 10,
        signin_tick_interval_ms: account.signin_tick_interval_ms ?? 1000,
        otp_tick_shots: account.otp_tick_shots ?? 10,
        otp_tick_interval_ms: account.otp_tick_interval_ms ?? 1000,
        slot_tick_shots: account.slot_tick_shots ?? 10,
        slot_tick_interval_ms: account.slot_tick_interval_ms ?? 1000,
        payment_tick_shots: account.payment_tick_shots ?? 10,
        payment_tick_interval_ms: account.payment_tick_interval_ms ?? 1000,
        saving: false,
    };
};

const updateTuning = async (account: Account) => {
    if (blockedByLock()) return;
    const row = tuningRows.value[account.id];
    if (!row) return;
    row.saving = true;
    try {
        await axios.put(`/api/accounts/${account.id}`, {
            signin_tick_shots: row.signin_tick_shots,
            signin_tick_interval_ms: row.signin_tick_interval_ms,
            otp_tick_shots: row.otp_tick_shots,
            otp_tick_interval_ms: row.otp_tick_interval_ms,
            slot_tick_shots: row.slot_tick_shots,
            slot_tick_interval_ms: row.slot_tick_interval_ms,
            payment_tick_shots: row.payment_tick_shots,
            payment_tick_interval_ms: row.payment_tick_interval_ms,
        });
        account.signin_tick_shots = row.signin_tick_shots;
        account.signin_tick_interval_ms = row.signin_tick_interval_ms;
        account.otp_tick_shots = row.otp_tick_shots;
        account.otp_tick_interval_ms = row.otp_tick_interval_ms;
        account.slot_tick_shots = row.slot_tick_shots;
        account.slot_tick_interval_ms = row.slot_tick_interval_ms;
        account.payment_tick_shots = row.payment_tick_shots;
        account.payment_tick_interval_ms = row.payment_tick_interval_ms;
        toast.success('Tuning saved.');
    } catch {
        toast.error('Failed to save tuning.');
    } finally {
        row.saving = false;
    }
};

const tuningDebounceTimers: Record<number, ReturnType<typeof setTimeout>> = {};

const onTuningBlur = (account: Account, field: keyof TuningRow, min: number) => {
    const row = tuningRows.value[account.id];
    if (!row) return;
    const val = (row as any)[field] as number;
    if (!Number.isFinite(val) || val < min) {
        (row as any)[field] = min;
    }
    clearTimeout(tuningDebounceTimers[account.id]);
    tuningDebounceTimers[account.id] = setTimeout(() => updateTuning(account), 300);
};

const applyBulkValue = (field: keyof typeof bulkApplyInputs.value) => {
    const value = bulkApplyInputs.value[field];
    if (value === null || !Number.isFinite(value)) return;

    for (const account of accounts.value) {
        if (!tuningRows.value[account.id]) {
            tuningRows.value[account.id] = { ...account };
        }
        (tuningRows.value[account.id] as any)[field] = value;
        clearTimeout(tuningDebounceTimers[account.id]);
        tuningDebounceTimers[account.id] = setTimeout(() => updateTuning(account), 300);
    }
    bulkApplyInputs.value[field] = null;
    toast.success(`Applied ${field} to all accounts.`);
};

const bulkPanelOpen = ref(true);

type BulkPairKey = 'signin' | 'otp' | 'slot' | 'payment';

const applyBulkPair = (phase: BulkPairKey) => {
    const shotsKey = `${phase}_tick_shots` as keyof typeof bulkApplyInputs.value;
    const msKey = `${phase}_tick_interval_ms` as keyof typeof bulkApplyInputs.value;
    const shots = bulkApplyInputs.value[shotsKey];
    const ms = bulkApplyInputs.value[msKey];

    if ((shots === null || !Number.isFinite(shots)) && (ms === null || !Number.isFinite(ms))) return;

    for (const account of accounts.value) {
        if (!tuningRows.value[account.id]) {
            tuningRows.value[account.id] = { ...account };
        }
        if (shots !== null && Number.isFinite(shots)) {
            (tuningRows.value[account.id] as any)[shotsKey] = shots;
        }
        if (ms !== null && Number.isFinite(ms)) {
            (tuningRows.value[account.id] as any)[msKey] = ms;
        }
        clearTimeout(tuningDebounceTimers[account.id]);
        tuningDebounceTimers[account.id] = setTimeout(() => updateTuning(account), 300);
    }

    const applied: string[] = [];
    if (shots !== null && Number.isFinite(shots)) { applied.push(`${shots} shots`); bulkApplyInputs.value[shotsKey] = DEFAULT_TICK_SHOTS; }
    if (ms !== null && Number.isFinite(ms)) { applied.push(`${ms}ms`); bulkApplyInputs.value[msKey] = DEFAULT_TICK_INTERVAL_MS; }
    toast.success(`${phase.toUpperCase()} → ${applied.join(', ')} applied to all accounts.`);
};

const updateAppointmentId = async (account: Account, value: string) => {
    if (blockedByLock()) return;
    const next = value.trim() || null;
    const current = account.appointment_id ?? null;
    if (next === current) return;
    try {
        await axios.put(`/api/accounts/${account.id}`, { appointment_id: next });
        account.appointment_id = next ?? undefined;
        account.appointment_id_updated_at = next ? new Date().toISOString() : null;
        toast.success('Appointment ID updated.');
    } catch {
        toast.error('Failed to update appointment ID.');
    }
};

// Expand an inclusive [from, to] date range into every YYYY-MM-DD in between,
// excluding Friday/Saturday (IVAC's Bangladesh weekend).
// Empty `from` → []; empty `to` → just [from] (unless it's a weekend); reversed range → [from] (unless it's a weekend).
const expandDateRange = (from: string, to: string): string[] => {
    if (!from) return [];
    const end = to || from;
    if (end < from) return isWeekendDate(from) ? [] : [from];
    const out: string[] = [];
    let cur = from;
    let guard = 0;
    while (cur <= end && guard < 366) {
        if (!isWeekendDate(cur)) {
            out.push(cur);
        }
        const dt = new Date(cur + 'T00:00:00Z');
        dt.setUTCDate(dt.getUTCDate() + 1);
        cur = dt.toISOString().slice(0, 10);
        guard++;
    }
    return out;
};

const persistAppointmentDates = async (account: Account, dates: string[]) => {
    if (blockedByLock()) return;
    try {
        await axios.put(`/api/accounts/${account.id}`, { appointment_dates: dates });
        account.appointment_dates = dates;
        toast.success('Appointment dates updated.');
    } catch {
        toast.error('Failed to update appointment dates.');
    }
};

const fetchPaymentLinkUrls = async () => {
    try {
        const response = await axios.get('/api/payment-links/phone-links');
        const urls: Record<string, string> = {};
        for (const { phone, url } of response.data as { phone: string; url: string }[]) {
            urls[phone] = url;
        }
        paymentLinkUrls.value = urls;
    } catch {
        // silently ignore — highlight/pay-now is best-effort
    }
};

interface LiveState {
    phone: string;
    phase: string | null;
    method: string | null;
    url: string | null;
    status_code: number | null;
    duration_ms: number | null;
    message: string | null;
    error_type: string | null;
    logged_at: string;
}

const LIVE_STATE_POLL_MS = 5000;
// Past this the call is no longer "live" — the row fades rather than disappearing, so a stalled
// account still shows where it got stuck instead of going blank.
const LIVE_STATE_STALE_MS = 60_000;

const liveStates = ref<Record<string, LiveState>>({});
// Bumped on every poll so the relative ages re-render. Tying it to the fetch rather than its own
// per-second interval keeps the displayed age exactly as fresh as the data behind it.
const liveStateClock = ref(Date.now());
let liveStateTimer: ReturnType<typeof setInterval> | null = null;

/**
 * Loaded separately from the accounts list so the table never waits on it, and skipped entirely
 * while the tab is in the background — an idle dashboard left open should cost nothing.
 */
const fetchLiveStates = async () => {
    if (document.hidden) {
        return;
    }
    try {
        const response = await axios.get('/api/accounts/live-states');
        const byPhone: Record<string, LiveState> = {};
        for (const row of response.data as LiveState[]) {
            byPhone[row.phone] = row;
        }
        liveStates.value = byPhone;
        liveStateClock.value = Date.now();
    } catch {
        // silently ignore — the next tick retries, and a stale cell is better than an error toast
        // firing every five seconds
    }
};

const onVisibilityChange = () => {
    if (!document.hidden) {
        fetchLiveStates();
    }
};

const liveStateAge = (state: LiveState): number => liveStateClock.value - new Date(state.logged_at).getTime();

const isLiveStateStale = (state: LiveState): boolean => liveStateAge(state) > LIVE_STATE_STALE_MS;

const liveStateAgeLabel = (state: LiveState): string => {
    const seconds = Math.max(0, Math.round(liveStateAge(state) / 1000));
    if (seconds < 60) return `${seconds}s ago`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    return `${Math.floor(seconds / 86400)}d ago`;
};

const liveStateCodeClass = (state: LiveState): string => {
    const code = state.status_code;
    if (code === null) return 'text-red-600 dark:text-red-400';
    if (code >= 200 && code < 300) return 'text-emerald-600 dark:text-emerald-400';
    if (code === 429) return 'text-orange-600 dark:text-orange-400';
    if (code >= 400) return 'text-red-600 dark:text-red-400';
    return 'text-zinc-500 dark:text-zinc-400';
};

// Mobile has no column header to read the outcome from, so the card carries a colored edge instead.
const liveStateBorderClass = (state: LiveState): string => {
    const code = state.status_code;
    if (code === null) return 'border-red-400 dark:border-red-600';
    if (code >= 200 && code < 300) return 'border-emerald-400 dark:border-emerald-600';
    if (code === 429) return 'border-orange-400 dark:border-orange-600';
    if (code >= 400) return 'border-red-400 dark:border-red-600';
    return 'border-zinc-300 dark:border-zinc-600';
};

// Transport failures never reach a status code, so the error type stands in for one.
const liveStateCodeLabel = (state: LiveState): string => (state.status_code === null ? (state.error_type ?? 'ERR') : String(state.status_code));

const liveStateTooltip = (state: LiveState): string =>
    [`${state.method ?? ''} ${state.url ?? ''}`.trim(), state.message, state.duration_ms !== null ? `${state.duration_ms}ms` : null]
        .filter(Boolean)
        .join('\n');

const fetchInvoiceTargets = async () => {
    try {
        const response = await axios.get('/api/payment-links/phone-invoices');
        const byPhone: Record<string, { txrId: string; archived: boolean }> = {};
        for (const { phone, txr_id, archived } of response.data as { phone: string; txr_id: string; archived: boolean }[]) {
            byPhone[phone] = { txrId: txr_id, archived };
        }
        invoicesByPhone.value = byPhone;
    } catch {
        // silently ignore — the invoice button is best-effort, like the pay-now link above
    }
};

const downloadAccountInvoice = async (account: Account) => {
    const target = invoicesByPhone.value[account.phone];
    if (!target) {
        return;
    }

    downloadingInvoicePhone.value = account.phone;
    try {
        const res = await axios.get('/api/payment-links/invoice', {
            params: { txrId: target.txrId },
            responseType: 'blob',
        });
        const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = `invoice-${account.phone}-${target.txrId}.pdf`;
        a.click();
        window.URL.revokeObjectURL(url);

        // A live fetch is banked server-side, so reflect that without a full reload.
        invoicesByPhone.value = {
            ...invoicesByPhone.value,
            [account.phone]: { ...target, archived: true },
        };
    } catch {
        toast.error('Failed to download invoice. IVAC may hold none for this reservation yet.');
    } finally {
        downloadingInvoicePhone.value = null;
    }
};

const getPdfAttachmentStatus = (account: Account): { text: string; count: number; hasAttachment: boolean } => {
    const count = account.pdfs_count ?? account.pdfs?.length ?? 0;
    return {
        text: count === 0 ? 'no pdf attached' : `${count} PDF${count > 1 ? 's' : ''}`,
        count,
        hasAttachment: count > 0,
    };
};

const totalPdfCount = computed(() =>
    accounts.value.reduce((sum, a) => sum + (a.pdfs_count ?? a.pdfs?.length ?? 0), 0)
);

// Web file numbers of the account's attachments, primary first (the order the list query returns).
// Managers and agents work off these IDs, so for them the Attachment column IS the ID list — the
// PDF count and the "uploaded" toggle are admin controls and are hidden. Admins keep that cell
// exactly as it was, with no IDs.
const showApplicationIds = computed(() => !isSuperAdmin.value);

// Only the attachments whose form carried a readable ID — one entry per PDF, so each rendered ID
// maps back to exactly one file.
const applicationIdPdfs = (account: Account): AccountPdf[] =>
    (account.pdfs ?? []).filter((pdf) => !!pdf.application_id);

/** The `account_pdfs.id` currently being fetched for viewing, so its ID can show a loading state. */
const openingPdfId = ref<number | null>(null);

/**
 * Opens the one PDF behind a clicked application ID.
 *
 * The list payload deliberately carries no base64, so the file has to be fetched first and then
 * picked out of the account payload by row id. The tab is opened up front, inside the click, because
 * a window.open() issued after an await has lost the user gesture and browsers block it as a popup.
 */
const openApplicationIdPdf = async (account: Account, pdf: AccountPdf) => {
    if (openingPdfId.value !== null) {
        return;
    }

    const tab = window.open('', '_blank');
    openingPdfId.value = pdf.id ?? null;
    try {
        const { data } = await axios.get(`/api/accounts/${account.id}`);
        const full = (data.pdfs ?? []).find((candidate: AccountPdf) => candidate.id === pdf.id);
        if (!full) {
            throw new Error('pdf not found');
        }

        const url = pdfObjectUrl(full);
        if (tab) {
            tab.location.href = url;
        } else {
            window.open(url, '_blank');
        }
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch {
        tab?.close();
        toast.error(`Failed to open ${pdf.application_id ?? pdf.name}.`);
    } finally {
        openingPdfId.value = null;
    }
};

// Attachment-count click → a list of this account's PDFs; clicking one opens it in a new tab.
// The list view never carries base64 (weight), so this fetches the single-account payload on
// demand — the same endpoint openEditDialog() already uses for the edit form's PDF list.
const pdfListDialogOpen = ref(false);
const pdfListLoading = ref(false);
const pdfListPdfs = ref<AccountPdf[]>([]);
const pdfListPhone = ref('');

const openPdfListDialog = async (account: Account) => {
    pdfListDialogOpen.value = true;
    pdfListLoading.value = true;
    pdfListPdfs.value = [];
    pdfListPhone.value = account.phone;
    try {
        const { data } = await axios.get(`/api/accounts/${account.id}`);
        pdfListPdfs.value = data.pdfs ?? [];
    } catch {
        toast.error('Failed to load PDFs.');
    } finally {
        pdfListLoading.value = false;
    }
};

// Prefers the Ghostscript-optimized copy (~80% smaller, same bytes the bot uploads to IVAC) and
// falls back to the pristine original when optimization never ran/succeeded for this PDF.
const pdfViewBase64 = (pdf: AccountPdf): string => pdf.optimized_base64 || pdf.base64;

const pdfDisplaySize = (pdf: AccountPdf): string => {
    const bytes = pdf.optimized_size ?? pdf.original_size ?? Math.round((pdfViewBase64(pdf).length * 3) / 4);
    return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.round(bytes / 1024)} KB`;
};

// Decodes the base64 into a File (not a plain Blob) so Chrome's built-in PDF viewer suggests the
// real filename on "Save As" instead of falling back to the blob's internal UUID. A data: URI
// would work too, but Chrome blocks large/top-level data: navigations in some contexts.
const pdfObjectUrl = (pdf: AccountPdf): string => {
    const byteChars = atob(pdfViewBase64(pdf));
    const byteNumbers = new Array(byteChars.length);
    for (let i = 0; i < byteChars.length; i++) {
        byteNumbers[i] = byteChars.charCodeAt(i);
    }

    return URL.createObjectURL(new File([new Uint8Array(byteNumbers)], pdf.name, { type: 'application/pdf' }));
};

const openPdfInNewTab = (pdf: AccountPdf) => {
    try {
        const url = pdfObjectUrl(pdf);
        window.open(url, '_blank');
        setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch {
        toast.error(`Failed to open ${pdf.name}.`);
    }
};

const paymentLinkStats = computed(() => {
    const total = accounts.value.length;
    const withLink = accounts.value.filter((a) => paymentLinkPhones.value.has(a.phone)).length;
    const percentage = total > 0 ? Math.round((withLink / total) * 1000) / 10 : 0;
    return { total, withLink, percentage };
});

const expandedTuningId = ref<number | null>(null);
const toggleTuning = (id: number) => {
    expandedTuningId.value = expandedTuningId.value === id ? null : id;
};

watch(statusFilter, () => fetchAccounts(1));

let paymentLinksChannel: ReturnType<typeof window.Echo.private> | null = null;

function subscribePaymentLinksEcho() {
    paymentLinksChannel = window.Echo.private('payment-links')
        .listen('.link.created', (e: { accountPhone: string | null; gatewayPageUrl: string | null }) => {
            if (e.accountPhone && e.gatewayPageUrl) {
                paymentLinkUrls.value = { ...paymentLinkUrls.value, [e.accountPhone]: e.gatewayPageUrl };
            }
        });
}

onMounted(() => {
    fetchAccounts();
    fetchGlobalDefaults();
    fetchPaymentLinkUrls();
    fetchInvoiceTargets();
    if (isSuperAdmin.value) {
        fetchUsers();
    }
    subscribePaymentLinksEcho();
    fetchLiveStates();
    liveStateTimer = setInterval(fetchLiveStates, LIVE_STATE_POLL_MS);
    document.addEventListener('visibilitychange', onVisibilityChange);
});

onBeforeUnmount(() => {
    if (paymentLinksChannel) {
        window.Echo.leave('payment-links');
    }
    if (liveStateTimer) {
        clearInterval(liveStateTimer);
        liveStateTimer = null;
    }
    document.removeEventListener('visibilitychange', onVisibilityChange);
});

const breadcrumbs = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Accounts', href: '/accounts' },
];
</script>

<template>

    <Head title="Accounts" />

    <AppLayout :breadcrumbs="breadcrumbs" full-width>
        <div class="flex h-full w-full flex-1 flex-col gap-3 sm:gap-6 p-3 sm:p-6">
            <div class="flex flex-col gap-3 sm:gap-4 md:flex-row md:items-start md:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 shadow-sm shadow-emerald-500/30">
                        <Users class="h-5 w-5 text-white" />
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Accounts</h2>
                        <p class="text-muted-foreground mt-0.5 text-xs sm:text-sm">Manage and monitor your IPMS automation accounts.</p>
                    </div>
                </div>
                <Button @click="openCreateDialog" class="w-full md:w-auto">
                    <Plus class="mr-2 h-4 w-4" />
                    Add Account
                </Button>
            </div>

            <div v-if="accountsLocked"
                class="flex items-start gap-2 rounded-lg border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                <Lock class="h-4 w-4 shrink-0 mt-px" />
                <span>Accounts are locked ({{ lockRangeLabel }}). You can still add accounts, but editing and deleting are unavailable until the window closes. A manager can make changes now.</span>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                <div class="relative flex-1 min-w-0">
                    <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input v-model="searchQuery" placeholder="Search by phone or user..." class="pl-9 w-full"
                        @input="onSearch" />
                </div>
                <span class="shrink-0 flex items-center gap-1.5 rounded-md border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/40 px-2.5 py-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-400" title="Total PDFs attached across listed accounts">
                    <FileText class="h-3.5 w-3.5 shrink-0" /> {{ totalPdfCount }} PDF{{ totalPdfCount === 1 ? '' : 's' }}
                </span>
                <span class="shrink-0 flex items-center gap-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900/40 px-2.5 py-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-300" title="Accounts with a payment link generated today">
                    <CreditCard class="h-3.5 w-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    {{ paymentLinkStats.withLink }} / {{ paymentLinkStats.total }} with payment link
                    <span class="font-semibold text-emerald-700 dark:text-emerald-400">({{ paymentLinkStats.percentage }}%)</span>
                </span>
                <button
                    @click="groupByAgent = !groupByAgent"
                    class="shrink-0 flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium transition-all active:scale-95"
                    :class="groupByAgent
                        ? 'border-blue-500 bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400 dark:border-blue-500'
                        : 'border-zinc-200 dark:border-zinc-700 text-zinc-500 hover:border-zinc-400'"
                >
                    <Users class="h-3.5 w-3.5 shrink-0" /> Group by Agent
                </button>
                <Button variant="outline" size="sm" class="shrink-0" @click="showPasswords = !showPasswords">
                    <component :is="showPasswords ? EyeOff : Eye" class="mr-2 h-4 w-4" />
                    {{ showPasswords ? 'Hide' : 'Show' }} Passwords
                </Button>
            </div>

            <!-- Bulk Apply Panel -->
            <div v-if="isSuperAdmin" class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 overflow-hidden">
                <!-- Header / toggle -->
                <button
                    @click="bulkPanelOpen = !bulkPanelOpen"
                    class="w-full flex items-center justify-between px-3 py-2 text-left"
                >
                    <div class="flex items-center gap-2">
                        <SlidersHorizontal class="h-3.5 w-3.5 text-zinc-500 dark:text-zinc-400 shrink-0" />
                        <span class="text-[11px] font-semibold text-zinc-700 dark:text-zinc-300 uppercase tracking-widest">Apply to All</span>
                        <span class="text-[10px] text-zinc-400 dark:text-zinc-500">— applies to all accounts</span>
                    </div>
                    <component :is="bulkPanelOpen ? ChevronUp : ChevronDown" class="h-3.5 w-3.5 text-zinc-400 shrink-0" />
                </button>

                <!-- Pairs grid -->
                <div v-if="bulkPanelOpen" class="px-3 pb-3 pt-1 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2">
                    <template v-for="phase in ([
                        { key: 'signin',  label: 'Sign-in' },
                        { key: 'otp',     label: 'OTP' },
                        { key: 'slot',    label: 'Slot' },
                        { key: 'payment', label: 'Payment' },
                    ] as const)" :key="phase.key">
                        <div class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2 flex flex-col gap-1.5">
                            <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-900 dark:text-zinc-100">{{ phase.label }}</div>
                            <div class="flex items-center gap-1.5">
                                <div class="flex-1 relative">
                                    <span class="absolute left-2 top-1/2 -translate-y-1/2 text-[9px] font-semibold text-zinc-400 pointer-events-none select-none">shots</span>
                                    <input
                                        v-model.number="(bulkApplyInputs as any)[`${phase.key}_tick_shots`]"
                                        @keydown.enter="applyBulkPair(phase.key)"
                                        type="number" min="1"
                                        placeholder="—"
                                        class="w-full h-7 rounded border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 pl-9 pr-1 text-[11px] text-right focus:outline-none focus:ring-1 focus:ring-zinc-400/50 [appearance:textfield] [&::-webkit-outer-spin-button]:[-webkit-appearance:none] [&::-webkit-inner-spin-button]:[-webkit-appearance:none]"
                                    />
                                </div>
                                <div class="flex-1 relative">
                                    <span class="absolute left-2 top-1/2 -translate-y-1/2 text-[9px] font-semibold text-zinc-400 pointer-events-none select-none">ms</span>
                                    <input
                                        v-model.number="(bulkApplyInputs as any)[`${phase.key}_tick_interval_ms`]"
                                        @keydown.enter="applyBulkPair(phase.key)"
                                        type="number" min="0" step="100"
                                        placeholder="—"
                                        class="w-full h-7 rounded border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 pl-6 pr-1 text-[11px] text-right focus:outline-none focus:ring-1 focus:ring-zinc-400/50 [appearance:textfield] [&::-webkit-outer-spin-button]:[-webkit-appearance:none] [&::-webkit-inner-spin-button]:[-webkit-appearance:none]"
                                    />
                                </div>
                                <button
                                    @click="applyBulkPair(phase.key)"
                                    class="shrink-0 h-7 px-2.5 rounded text-[11px] font-semibold text-white bg-zinc-900 hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300 transition-colors"
                                >Apply</button>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Batch appointment date range + setup gate toggles -->
                <div v-if="bulkPanelOpen" class="px-3 pb-3 pt-1 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-2">
                    <div class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2 flex flex-col gap-1.5">
                        <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-900 dark:text-zinc-100 flex items-center gap-1.5">
                            <CalendarRange class="h-3.5 w-3.5 shrink-0 text-zinc-400" /> Appointment Dates
                        </div>
                        <div class="flex items-center justify-between gap-1.5">
                            <DateRangePicker
                                v-model="bulkDateRange"
                                placeholder="Select date range"
                                trigger-class="flex-1 h-7 text-[11px]"
                                :disabled="bulkDateRangeBusy"
                            />
                            <span class="shrink-0 text-[10px] text-zinc-400 tabular-nums">{{ expandDateRange(bulkDateRange.from, bulkDateRange.to).length }}d</span>
                            <button
                                @click="applyBulkDateRange"
                                :disabled="!bulkDateRange.from || bulkDateRangeBusy"
                                class="shrink-0 h-7 px-2.5 rounded text-[11px] font-semibold text-white bg-zinc-900 hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300 transition-colors disabled:opacity-50"
                            >Apply</button>
                        </div>
                    </div>
                    <div class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2 flex flex-col gap-1.5">
                        <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-zinc-900 dark:text-zinc-100">
                            <FileText class="h-3.5 w-3.5 shrink-0 text-zinc-400" /> Attachment
                        </div>
                        <div class="flex items-center justify-between gap-1.5">
                            <button
                                @click="bulkSetPdfUploaded(true)"
                                :disabled="bulkSetupBusy.pdf"
                                class="flex-1 h-7 px-2.5 rounded text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 border border-emerald-200 dark:border-emerald-800 transition-colors disabled:opacity-50"
                            >Mark All Done</button>
                            <button
                                @click="bulkSetPdfUploaded(false)"
                                :disabled="bulkSetupBusy.pdf"
                                class="flex-1 h-7 px-2.5 rounded text-[11px] font-semibold text-zinc-600 dark:text-zinc-300 bg-zinc-50 dark:bg-zinc-800 hover:bg-zinc-100 dark:hover:bg-zinc-700 border border-zinc-200 dark:border-zinc-700 transition-colors disabled:opacity-50"
                            >Mark All Pending</button>
                        </div>
                    </div>
                    <div class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2 flex flex-col gap-1.5">
                        <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-zinc-900 dark:text-zinc-100">
                            <ToggleLeft class="h-3.5 w-3.5 shrink-0 text-zinc-400" /> Booking Cfg
                        </div>
                        <div class="flex items-center justify-between gap-1.5">
                            <button
                                @click="bulkSetBookingConfigured(true)"
                                :disabled="bulkSetupBusy.booking"
                                class="flex-1 h-7 px-2.5 rounded text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 border border-emerald-200 dark:border-emerald-800 transition-colors disabled:opacity-50"
                            >Mark All Done</button>
                            <button
                                @click="bulkSetBookingConfigured(false)"
                                :disabled="bulkSetupBusy.booking"
                                class="flex-1 h-7 px-2.5 rounded text-[11px] font-semibold text-zinc-600 dark:text-zinc-300 bg-zinc-50 dark:bg-zinc-800 hover:bg-zinc-100 dark:hover:bg-zinc-700 border border-zinc-200 dark:border-zinc-700 transition-colors disabled:opacity-50"
                            >Mark All Pending</button>
                        </div>
                    </div>
                    <div class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2 flex flex-col gap-1.5">
                        <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-zinc-900 dark:text-zinc-100">
                            <Power class="h-3.5 w-3.5 shrink-0 text-zinc-400" /> Active
                        </div>
                        <div class="flex items-center justify-between gap-1.5">
                            <button
                                @click="bulkSetActive(true)"
                                :disabled="bulkSetupBusy.active"
                                class="flex-1 h-7 px-2.5 rounded text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 border border-emerald-200 dark:border-emerald-800 transition-colors disabled:opacity-50"
                            >Mark All Active</button>
                            <button
                                @click="bulkSetActive(false)"
                                :disabled="bulkSetupBusy.active"
                                class="flex-1 h-7 px-2.5 rounded text-[11px] font-semibold text-zinc-600 dark:text-zinc-300 bg-zinc-50 dark:bg-zinc-800 hover:bg-zinc-100 dark:hover:bg-zinc-700 border border-zinc-200 dark:border-zinc-700 transition-colors disabled:opacity-50"
                            >Mark All Inactive</button>
                        </div>
                    </div>
                    <div class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2 flex flex-col gap-1.5">
                        <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-zinc-900 dark:text-zinc-100">
                            <CreditCard class="h-3.5 w-3.5 shrink-0 text-zinc-400" /> Auto Pay
                        </div>
                        <div class="flex items-center justify-between gap-1.5">
                            <button
                                @click="rearmAllAutoPayment"
                                title="Clear the paid block on every listed account so auto payment can run again"
                                class="flex-1 h-7 px-2.5 rounded text-[11px] font-semibold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 hover:bg-amber-100 dark:hover:bg-amber-900/50 border border-amber-200 dark:border-amber-800 transition-colors disabled:opacity-50"
                            >Re-arm All</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Tabs -->
            <div class="flex gap-1 border-b border-zinc-200 dark:border-zinc-800 whitespace-nowrap -mx-3 md:mx-0 px-3 md:px-0">
                <button
                    v-for="status in ['running', 'completed', 'cancelled', 'all']"
                    :key="status"
                    @click="statusFilter = status as any"
                    :class="[
                        'px-3 sm:px-4 py-2 text-xs sm:text-sm font-medium transition-colors border-b-2 -mb-px shrink-0',
                        statusFilter === status
                            ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400'
                            : 'border-transparent text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300'
                    ]"
                >
                    {{ status.charAt(0).toUpperCase() + status.slice(1) }}
                </button>
            </div>

            <div class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm overflow-hidden">

                <!-- Mobile cards (< md) -->
                <div class="md:hidden divide-y divide-zinc-200/60 dark:divide-zinc-700/60">
                    <template v-if="loading">
                        <div v-for="i in 3" :key="i" class="px-3 py-3 space-y-2">
                            <div class="h-4 animate-pulse rounded bg-muted"></div>
                            <div class="h-3 animate-pulse rounded bg-muted w-2/3"></div>
                        </div>
                    </template>
                    <div v-else-if="accounts.length === 0" class="px-4 py-8 text-center text-sm text-zinc-400">
                        No accounts found.
                    </div>
                    <template v-else v-for="group in groupedAccounts" :key="group.key || 'all'">
                    <div v-if="groupByAgent" class="px-3 py-1.5 bg-zinc-100/70 dark:bg-zinc-800/40 text-[10px] font-bold uppercase tracking-widest text-zinc-600 dark:text-zinc-300 border-y border-zinc-200 dark:border-zinc-700">
                        {{ group.label }}
                        <span class="ml-1.5 text-zinc-400 font-normal normal-case">{{ group.items.length }} account{{ group.items.length === 1 ? '' : 's' }}</span>
                    </div>
                    <div v-for="{ account } in group.items" :key="account.id"
                        class="px-3 py-2.5 transition-colors"
                        :class="paymentLinkPhones.has(account.phone) ? 'bg-emerald-50 dark:bg-emerald-900/20' : ''">
                        <!-- Row 1: icon + phone/email + actions -->
                        <div class="flex items-center gap-2">
                            <div class="p-1.5 rounded bg-blue-100 dark:bg-blue-900/20 shrink-0">
                                <CircleUser class="h-4 w-4 text-blue-600 dark:text-blue-400" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="font-semibold text-[12px] tabular-nums">{{ account.phone }}</span>
                                    <span class="text-[9px] text-zinc-400">#{{ account.id }}</span>
                                    <span v-if="account.status === 'completed'"
                                        class="rounded bg-emerald-100 px-1.5 py-px text-[10px] font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200"
                                        title="Booking finished — a payment link for this account is marked paid">
                                        COMPLETED
                                    </span>
                                    <span v-if="account.tag" class="bg-zinc-100 text-black rounded px-1.5 py-px text-[10px] font-semibold">{{ account.tag }}</span>
                                    <span v-if="account.booking_city" class="bg-blue-50 text-black rounded px-1.5 py-px text-[10px] font-semibold">{{ account.booking_city }}</span>
                                    <Button as="a" v-if="paymentLinkUrls[account.phone]" :href="paymentLinkUrls[account.phone]"
                                        target="_blank" rel="noopener noreferrer" size="sm" class="h-5 px-1.5 text-[10px] font-semibold">
                                        Pay Now
                                    </Button>
                                    <button v-if="invoicesByPhone[account.phone]" @click="downloadAccountInvoice(account)"
                                        class="inline-flex items-center gap-1 h-5 px-1.5 rounded border text-[10px] font-semibold disabled:opacity-50"
                                        :class="invoicesByPhone[account.phone].archived
                                            ? 'border-emerald-300 text-emerald-700 dark:border-emerald-700 dark:text-emerald-400'
                                            : 'border-blue-300 text-blue-700 dark:border-blue-700 dark:text-blue-400'"
                                        :title="invoicesByPhone[account.phone].archived ? 'Download invoice (archived)' : 'Download invoice (fetches from IVAC)'"
                                        :disabled="downloadingInvoicePhone === account.phone">
                                        <Loader2 v-if="downloadingInvoicePhone === account.phone" class="h-3 w-3 animate-spin" />
                                        <img v-else src="/images/pdf.png" alt="" class="h-3.5 w-3.5"
                                            :class="invoicesByPhone[account.phone].archived ? '' : 'opacity-60'" />
                                        Invoice
                                    </button>
                                </div>
                                <div class="text-[10px] text-zinc-500 mt-0.5">
                                    <span v-if="account.email">{{ account.email }}</span>
                                    <span v-else class="italic text-zinc-400">no email</span>
                                </div>
                            </div>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button variant="ghost" size="icon" class="h-7 w-7 shrink-0">
                                        <MoreVertical class="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-40">
                                    <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem :disabled="accountsLocked" @click="openEditDialog(account)">
                                        <Edit2 class="mr-2 h-4 w-4" /> Edit
                                    </DropdownMenuItem>
                                    <DropdownMenuItem :disabled="accountsLocked" @click="deleteAccount(account.id)" class="text-destructive">
                                        <Trash2 class="mr-2 h-4 w-4" /> Delete
                                    </DropdownMenuItem>
                                    <div v-if="accountsLocked" class="px-2 py-1 text-[10px] text-muted-foreground">
                                        Locked until {{ lockRangeLabel.split('\u2013')[1] || 'the window closes' }}
                                    </div>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                        <!-- Row 2: live bot state — sits directly under the phone because during a
                             booking window it is the one field being watched. -->
                        <div v-if="liveStates[account.phone]" class="mt-1.5 pl-[2.25rem]">
                            <div class="flex items-center gap-1.5 rounded border-l-2 bg-zinc-50 px-1.5 py-1 text-[10px] leading-tight dark:bg-zinc-800/40"
                                :class="[liveStateBorderClass(liveStates[account.phone]), isLiveStateStale(liveStates[account.phone]) ? 'opacity-40' : '']"
                                :title="liveStateTooltip(liveStates[account.phone])">
                                <span class="shrink-0 font-semibold text-zinc-700 dark:text-zinc-200">{{ liveStates[account.phone].phase ?? '—' }}</span>
                                <span class="shrink-0 font-mono font-bold tabular-nums" :class="liveStateCodeClass(liveStates[account.phone])">{{ liveStateCodeLabel(liveStates[account.phone]) }}</span>
                                <span v-if="liveStates[account.phone].message" class="truncate text-zinc-500 dark:text-zinc-400">{{ liveStates[account.phone].message }}</span>
                                <span class="ml-auto shrink-0 text-[9px] tabular-nums text-zinc-400">{{ liveStateAgeLabel(liveStates[account.phone]) }}</span>
                            </div>
                        </div>
                        <!-- Row 3: password + active toggle + status select -->
                        <div class="mt-1.5 pl-[2.25rem] flex items-center gap-2 flex-wrap">
                            <code class="px-1.5 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 text-[10px] font-mono">
                                {{ showPasswords ? account.password : '••••••••' }}
                            </code>
                            <div v-if="showAdminColumns" class="flex items-center gap-1.5">
                                <Switch :model-value="account.single_sign_in"
                                    @update:model-value="(val: boolean) => updateSingleSignIn(account, val)" />
                                <span class="text-[10px] text-zinc-400">1× SI</span>
                            </div>
                            <div v-if="showAdminColumns" class="flex items-center gap-1.5" title="Toggle account active/inactive">
                                <Switch :model-value="account.is_active"
                                    @update:model-value="(val: boolean) => updateStatus(account, val)" />
                                <span :class="account.is_active ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400'" class="text-[10px] font-semibold">
                                    {{ account.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                            <select
                                v-if="showAdminColumns"
                                :value="account.status"
                                class="h-7 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 text-[10px]"
                                @change="updateAccountStatus(account, ($event.target as HTMLSelectElement).value as any)"
                            >
                                <option value="running">Running</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                            <span v-if="account.user" class="text-[10px] text-zinc-400">{{ account.user.name }}</span>
                        </div>
                        <!-- Row 4: Appointment ID + Date -->
                        <div class="mt-1.5 pl-[2.25rem] flex items-start gap-2">
                            <div class="flex-1">
                                <input
                                    type="text"
                                    :value="account.appointment_id ?? ''"
                                    @blur="updateAppointmentId(account, ($event.target as HTMLInputElement).value)"
                                    @keydown.enter="($event.target as HTMLInputElement).blur()"
                                    placeholder="Appointment ID"
                                    class="w-full h-7 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 text-[11px] font-mono tabular-nums focus:outline-none focus:ring-1 focus:ring-emerald-500/50 focus:border-emerald-400 dark:focus:border-emerald-600 transition-colors"
                                />
                                <p v-if="account.appointment_id_updated_at" class="mt-0.5 text-[10px] text-zinc-400 dark:text-zinc-500 tabular-nums">
                                    updated {{ new Date(account.appointment_id_updated_at).toLocaleString() }}
                                </p>
                            </div>
                            <div v-if="showAdminColumns" class="shrink-0 flex flex-col gap-1" title="Appointment date range (slot reserve, Fri/Sat excluded)">
                                <DateRangePicker
                                    :model-value="{ from: account.appointment_dates?.[0] ?? '', to: account.appointment_dates?.[(account.appointment_dates?.length ?? 0) - 1] ?? '' }"
                                    @update:model-value="(v) => { if (v.from && v.to) persistAppointmentDates(account, expandDateRange(v.from, v.to)); }"
                                    placeholder="Set dates"
                                    trigger-class="h-7 w-36 text-[11px]"
                                />
                                <span v-if="(account.appointment_dates?.length ?? 0) > 0" class="text-[9px] text-zinc-400 pl-1">{{ account.appointment_dates?.length }} date(s)</span>
                            </div>
                        </div>
                        <!-- Row 5: Attachment Status + setup toggles -->
                        <div class="mt-1.5 pl-[2.25rem] flex items-center gap-3 flex-wrap">
                            <template v-if="showApplicationIds">
                                <div v-if="applicationIdPdfs(account).length" class="flex flex-wrap items-center gap-2">
                                    <button v-for="pdf in applicationIdPdfs(account)" :key="pdf.id"
                                        type="button" @click="openApplicationIdPdf(account, pdf)"
                                        :disabled="openingPdfId !== null"
                                        class="font-mono text-[11px] font-bold text-zinc-900 dark:text-zinc-100 disabled:opacity-50"
                                        :title="`Open ${pdf.name}`">
                                        {{ pdf.application_id }}<span v-if="openingPdfId === pdf.id" class="ml-1 font-normal text-zinc-400">…</span>
                                    </button>
                                </div>
                                <span v-else class="inline-block px-2 py-1 rounded-none bg-red-600 dark:bg-red-700 text-white text-[10px] font-semibold">
                                    no pdf attached
                                </span>
                            </template>
                            <template v-else>
                                <button v-if="getPdfAttachmentStatus(account).hasAttachment" type="button"
                                    @click="openPdfListDialog(account)"
                                    class="inline-block px-2 py-1 rounded bg-emerald-100 text-black text-[10px] font-semibold hover:bg-emerald-200 dark:hover:bg-emerald-200/80 transition-colors">
                                    {{ getPdfAttachmentStatus(account).text }}
                                </button>
                                <span v-else class="inline-block px-2 py-1 rounded-none bg-red-600 dark:bg-red-700 text-white text-[10px] font-semibold">
                                    {{ getPdfAttachmentStatus(account).text }}
                                </span>
                                <div class="flex items-center gap-1.5">
                                    <Switch :model-value="!!account.pdf_uploaded"
                                        @update:model-value="(val: boolean) => updatePdfUploaded(account, val)" />
                                    <span class="text-[10px] text-zinc-400">PDF uploaded</span>
                                </div>
                            </template>
                            <div v-if="showAdminColumns" class="flex items-center gap-1.5">
                                <Switch :model-value="!!account.booking_configured"
                                    @update:model-value="(val: boolean) => updateBookingConfigured(account, val)" />
                                <span class="text-[10px] text-zinc-400">Booking cfg</span>
                            </div>
                        </div>
                        <!-- Row 6: Tuning accordion toggle -->
                        <div v-if="showAdminColumns" class="mt-1.5 pl-[2.25rem]">
                            <button
                                @click="toggleTuning(account.id)"
                                class="flex items-center gap-1 text-[10px] text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors"
                            >
                                <component :is="expandedTuningId === account.id ? ChevronUp : ChevronDown" class="h-3 w-3" />
                                Tick tuning
                            </button>
                            <div v-if="expandedTuningId === account.id && tuningRows[account.id]" class="mt-2">
                                <div class="grid gap-x-2 gap-y-1.5" style="grid-template-columns: 2.5rem 1fr 1fr">
                                    <span class="text-[9px] text-zinc-400 uppercase font-semibold"></span>
                                    <span class="text-[9px] text-zinc-400 uppercase font-semibold text-center">Shots</span>
                                    <span class="text-[9px] text-zinc-400 uppercase font-semibold text-center">ms</span>

                                    <span class="text-[10px] text-zinc-500 self-center font-medium">SI</span>
                                    <input type="number" min="1" v-model.number="tuningRows[account.id].signin_tick_shots"
                                        @blur="onTuningBlur(account, 'signin_tick_shots', 1)"
                                        @keydown.enter="($event.target as HTMLInputElement).blur()"
                                        class="w-full h-7 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[10px] tabular-nums text-center focus:outline-none focus:ring-1 focus:ring-emerald-500/50 focus:border-emerald-400 dark:focus:border-emerald-600 transition-colors" />
                                    <input type="number" min="100" step="100" v-model.number="tuningRows[account.id].signin_tick_interval_ms"
                                        @blur="onTuningBlur(account, 'signin_tick_interval_ms', 100)"
                                        @keydown.enter="($event.target as HTMLInputElement).blur()"
                                        class="w-full h-7 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[10px] tabular-nums text-center focus:outline-none focus:ring-1 focus:ring-emerald-500/50 focus:border-emerald-400 dark:focus:border-emerald-600 transition-colors" />

                                    <span class="text-[10px] text-zinc-500 self-center font-medium">OTP</span>
                                    <input type="number" min="1" v-model.number="tuningRows[account.id].otp_tick_shots"
                                        @blur="onTuningBlur(account, 'otp_tick_shots', 1)"
                                        @keydown.enter="($event.target as HTMLInputElement).blur()"
                                        class="w-full h-7 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[10px] tabular-nums text-center focus:outline-none focus:ring-1 focus:ring-emerald-500/50 focus:border-emerald-400 dark:focus:border-emerald-600 transition-colors" />
                                    <input type="number" min="100" step="100" v-model.number="tuningRows[account.id].otp_tick_interval_ms"
                                        @blur="onTuningBlur(account, 'otp_tick_interval_ms', 100)"
                                        @keydown.enter="($event.target as HTMLInputElement).blur()"
                                        class="w-full h-7 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[10px] tabular-nums text-center focus:outline-none focus:ring-1 focus:ring-emerald-500/50 focus:border-emerald-400 dark:focus:border-emerald-600 transition-colors" />

                                    <span class="text-[10px] text-zinc-500 self-center font-medium">Slot</span>
                                    <input type="number" min="1" v-model.number="tuningRows[account.id].slot_tick_shots"
                                        @blur="onTuningBlur(account, 'slot_tick_shots', 1)"
                                        @keydown.enter="($event.target as HTMLInputElement).blur()"
                                        class="w-full h-7 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[10px] tabular-nums text-center focus:outline-none focus:ring-1 focus:ring-emerald-500/50 focus:border-emerald-400 dark:focus:border-emerald-600 transition-colors" />
                                    <input type="number" min="100" step="100" v-model.number="tuningRows[account.id].slot_tick_interval_ms"
                                        @blur="onTuningBlur(account, 'slot_tick_interval_ms', 100)"
                                        @keydown.enter="($event.target as HTMLInputElement).blur()"
                                        class="w-full h-7 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[10px] tabular-nums text-center focus:outline-none focus:ring-1 focus:ring-emerald-500/50 focus:border-emerald-400 dark:focus:border-emerald-600 transition-colors" />

                                    <span class="text-[10px] text-zinc-500 self-center font-medium">Pay</span>
                                    <input type="number" min="1" v-model.number="tuningRows[account.id].payment_tick_shots"
                                        @blur="onTuningBlur(account, 'payment_tick_shots', 1)"
                                        @keydown.enter="($event.target as HTMLInputElement).blur()"
                                        class="w-full h-7 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[10px] tabular-nums text-center focus:outline-none focus:ring-1 focus:ring-emerald-500/50 focus:border-emerald-400 dark:focus:border-emerald-600 transition-colors" />
                                    <input type="number" min="100" step="100" v-model.number="tuningRows[account.id].payment_tick_interval_ms"
                                        @blur="onTuningBlur(account, 'payment_tick_interval_ms', 100)"
                                        @keydown.enter="($event.target as HTMLInputElement).blur()"
                                        class="w-full h-7 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[10px] tabular-nums text-center focus:outline-none focus:ring-1 focus:ring-emerald-500/50 focus:border-emerald-400 dark:focus:border-emerald-600 transition-colors" />
                                </div>
                            </div>
                        </div>
                    </div>
                    </template>
                </div>

                <!-- Desktop table (>= md) -->
                <div class="hidden md:block overflow-x-auto">
                    <Table class="border-b">
                        <TableHeader class="bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm border-b border-zinc-200/60 dark:border-zinc-700/60">
                            <TableRow>
                                <TableHead class="pl-2 pr-1 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest border-r w-[2rem]">S/N</TableHead>
                                <TableHead class="px-1.5 py-1 text-left font-semibold text-zinc-400 text-[9px] uppercase tracking-widest">Account</TableHead>
                                <TableHead class="px-1 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest border-l border-r border-zinc-200 dark:border-zinc-700">Center</TableHead>
                                <TableHead class="px-1 py-1 text-left font-semibold text-zinc-400 text-[9px] uppercase tracking-widest border-r border-zinc-200 dark:border-zinc-700 w-[9.5rem]">Live State</TableHead>
                                <TableHead v-if="showAdminColumns" class="px-1 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest border-r border-zinc-200 dark:border-zinc-700">Dates</TableHead>
                                <TableHead v-if="showPasswords" class="px-1 py-1 text-left font-semibold text-zinc-400 text-[9px] uppercase tracking-widest">Password</TableHead>
                                <TableHead v-if="showAdminColumns" class="px-1 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest whitespace-nowrap">Single SI</TableHead>
                                <TableHead v-if="showAdminColumns" class="px-1 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest whitespace-nowrap border-l border-r border-zinc-200 dark:border-zinc-700">Tuning</TableHead>
                                <TableHead v-if="showAdminColumns" class="px-1 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest border-l border-r border-zinc-200 dark:border-zinc-700">Status</TableHead>
                                <TableHead v-if="showAdminColumns" class="px-1 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest border-r border-zinc-200 dark:border-zinc-700">Active</TableHead>
                                <TableHead class="px-1 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest border-r border-zinc-200 dark:border-zinc-700">
                                    Attachment
                                    <span v-if="!showApplicationIds" class="ml-1 inline-block rounded bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400 px-1 py-px normal-case tracking-normal font-bold" title="Total PDFs attached across listed accounts">{{ totalPdfCount }}</span>
                                </TableHead>
                                <TableHead v-if="showAdminColumns" class="px-1 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest whitespace-nowrap border-r border-zinc-200 dark:border-zinc-700">Booking Cfg</TableHead>
                                <TableHead class="px-1 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest">Managed By</TableHead>
                                <TableHead class="px-1 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest border-l border-zinc-200 dark:border-zinc-700">Auto Payment</TableHead>
                                <TableHead class="px-1 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest border-l border-r border-zinc-200 dark:border-zinc-700">Payment Link</TableHead>
                                <TableHead class="px-1 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest border-r border-zinc-200 dark:border-zinc-700">Invoice</TableHead>
                                <TableHead class="pl-2 pr-3 py-1 text-center font-semibold text-zinc-400 text-[9px] uppercase tracking-widest border-l w-[3.125rem]">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="loading" v-for="i in 3" :key="i">
                                <TableCell v-for="j in desktopColumnCount" :key="j" :class="{ 'border-r': j === 1, 'border-l': j === desktopColumnCount }">
                                    <div class="h-5 w-full animate-pulse rounded bg-muted"></div>
                                </TableCell>
                            </TableRow>

                            <TableRow v-else-if="accounts.length === 0" class="h-24 text-center">
                                <TableCell :colspan="desktopColumnCount" class="text-muted-foreground">
                                    No accounts found.
                                </TableCell>
                            </TableRow>

                            <template v-else v-for="group in groupedAccounts" :key="group.key || 'all'">
                            <TableRow v-if="groupByAgent" class="bg-zinc-100/70 dark:bg-zinc-800/40">
                                <TableCell :colspan="desktopColumnCount" class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-widest text-zinc-600 dark:text-zinc-300">
                                    {{ group.label }}
                                    <span class="ml-1.5 text-zinc-400 font-normal normal-case">{{ group.items.length }} account{{ group.items.length === 1 ? '' : 's' }}</span>
                                </TableCell>
                            </TableRow>
                            <template v-for="{ account, idx } in group.items" :key="account.id">
                            <TableRow
                                class="transition-colors hover:bg-sky-50 dark:hover:bg-sky-900/20"
                                :class="paymentLinkPhones.has(account.phone) ? 'bg-emerald-100 dark:bg-emerald-900/40' : idx % 2 === 0 ? 'bg-white dark:bg-zinc-950/20' : 'bg-zinc-100/70 dark:bg-zinc-800/30'"
                            >
                                <TableCell class="pl-2 pr-1 py-1 text-center text-[9px] text-zinc-400 dark:text-zinc-600 font-mono tabular-nums border-r">
                                    {{ (paginationMeta.current_page - 1) * PER_PAGE + idx + 1 }}
                                </TableCell>
                                <TableCell class="px-1.5 py-1 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5 min-w-[10.625rem]">
                                        <div class="p-1 rounded bg-blue-100 dark:bg-blue-900/20 shrink-0">
                                            <CircleUser class="h-3.5 w-3.5 text-blue-600 dark:text-blue-400" />
                                        </div>
                                        <div class="flex flex-col gap-0 flex-1">
                                            <div class="font-semibold text-[11px] text-zinc-900 dark:text-zinc-100 tabular-nums leading-tight">
                                                {{ account.phone }}
                                                <span class="ml-1 text-[9px] font-normal text-zinc-400">#{{ account.id }}</span>
                                                <span v-if="account.status === 'completed'"
                                                    class="ml-1 rounded bg-emerald-100 px-1 py-0.5 text-[9px] font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200"
                                                    title="Booking finished — a payment link for this account is marked paid">
                                                    COMPLETED
                                                </span>
                                            </div>
                                            <div class="text-[9px] text-zinc-500 dark:text-zinc-400 leading-tight">
                                                <span v-if="account.email">{{ account.email }}</span>
                                                <span v-else class="italic text-zinc-400">no email</span>
                                            </div>
                                            <div v-if="account.tag" class="leading-tight">
                                                <span class="inline-block bg-zinc-100 text-black rounded px-1 py-0.5 text-[9px] font-semibold">{{ account.tag }}</span>
                                            </div>
                                            <div class="text-[8px] text-zinc-400 tabular-nums leading-tight">{{ formatDate(account.created_at) }}</div>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell class="px-1 py-1 text-[10px] whitespace-nowrap text-center border-l border-r border-zinc-200 dark:border-zinc-700">
                                    <div class="flex flex-col items-center gap-0.5">
                                        <span v-if="account.booking_city" class="font-bold uppercase text-zinc-900 dark:text-zinc-100">{{ account.booking_city }}</span>
                                        <span v-else class="text-zinc-400">—</span>
                                        <span v-if="account.auto_payment"
                                            class="rounded bg-emerald-100 px-1 py-0.5 text-[9px] font-semibold text-black dark:bg-emerald-900/40 dark:text-emerald-200"
                                            :title="`Auto payment via ${account.auto_payment_method ?? '—'} (${account.auto_payment_wallet ?? 'no wallet'})`">
                                            AUTO PAY
                                        </span>
                                        <span v-if="account.auto_payment && account.auto_payment_paid"
                                            class="flex items-center gap-1">
                                            <span class="rounded bg-amber-100 px-1 py-0.5 text-[9px] font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-200"
                                                title="Auto payment is blocked — this account already has a completed payment. Re-arm it for the next booking cycle.">
                                                PAID
                                            </span>
                                            <button type="button" @click="rearmAutoPayment(account)"
                                                class="rounded border border-amber-400 px-1 py-0.5 text-[9px] font-semibold text-amber-700 hover:bg-amber-50 dark:text-amber-300 dark:hover:bg-amber-900/30"
                                                title="Allow auto payment to run again for this account">
                                                Re-arm
                                            </button>
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell class="px-1 py-1 text-[10px] border-r border-zinc-200 dark:border-zinc-700 w-[9.5rem]">
                                    <div v-if="liveStates[account.phone]"
                                        class="flex flex-col gap-px leading-tight"
                                        :class="isLiveStateStale(liveStates[account.phone]) ? 'opacity-40' : ''"
                                        :title="liveStateTooltip(liveStates[account.phone])">
                                        <div class="flex items-baseline justify-between gap-1">
                                            <span class="font-semibold text-zinc-700 dark:text-zinc-200 truncate">{{ liveStates[account.phone].phase ?? '—' }}</span>
                                            <span class="font-mono font-bold tabular-nums shrink-0" :class="liveStateCodeClass(liveStates[account.phone])">{{ liveStateCodeLabel(liveStates[account.phone]) }}</span>
                                        </div>
                                        <div v-if="liveStates[account.phone].message" class="truncate text-[9px] text-zinc-500 dark:text-zinc-400">{{ liveStates[account.phone].message }}</div>
                                        <div class="text-right text-[8px] tabular-nums text-zinc-400">{{ liveStateAgeLabel(liveStates[account.phone]) }}</div>
                                    </div>
                                    <span v-else class="text-zinc-400 dark:text-zinc-600">—</span>
                                </TableCell>
                                <!-- Appointment date range -->
                                <TableCell v-if="showAdminColumns" class="px-1 py-1 text-[10px] whitespace-nowrap text-center border-r border-zinc-200 dark:border-zinc-700">
                                    <div class="flex flex-col items-center gap-0.5" title="Appointment date range (slot reserve, Fri/Sat excluded)">
                                        <DateRangePicker
                                            :model-value="{ from: account.appointment_dates?.[0] ?? '', to: account.appointment_dates?.[(account.appointment_dates?.length ?? 0) - 1] ?? '' }"
                                            @update:model-value="(v) => { if (v.from && v.to) persistAppointmentDates(account, expandDateRange(v.from, v.to)); }"
                                            placeholder="Set dates"
                                            trigger-class="h-6 w-[11.875rem] text-[9px]"
                                        />
                                        <span v-if="(account.appointment_dates?.length ?? 0) > 0" class="text-[8px] text-zinc-400 tabular-nums">{{ account.appointment_dates?.length }} date(s)</span>
                                    </div>
                                </TableCell>
                                <TableCell v-if="showPasswords" class="px-1 py-1 text-[10px] whitespace-nowrap">
                                    <code class="px-1 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 text-[9px] font-mono">
                                        {{ account.password }}
                                    </code>
                                </TableCell>
                                <TableCell v-if="showAdminColumns" class="px-1 py-1 text-[10px] whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-2">
                                        <Switch :model-value="account.single_sign_in"
                                            @update:model-value="(val: boolean) => updateSingleSignIn(account, val)" />
                                    </div>
                                </TableCell>
                                <!-- Tuning summary — collapses the 4 race phases into one column; click to expand the editable grid below -->
                                <TableCell v-if="showAdminColumns" class="px-1 py-1 text-center whitespace-nowrap border-l border-r border-zinc-200 dark:border-zinc-700">
                                    <button
                                        v-if="tuningRows[account.id]"
                                        type="button"
                                        @click="toggleTuning(account.id)"
                                        class="inline-flex items-center gap-1 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 py-1 text-left hover:border-zinc-400 dark:hover:border-zinc-500 transition-colors"
                                        title="Click to edit race tuning"
                                    >
                                        <div class="grid grid-cols-2 gap-x-2 gap-y-0 font-mono text-[9px] leading-tight text-zinc-600 dark:text-zinc-300 tabular-nums">
                                            <div class="flex justify-between gap-1"><span class="text-zinc-400">SI</span><span>{{ tuningRows[account.id].signin_tick_shots }}×{{ fmtInterval(tuningRows[account.id].signin_tick_interval_ms) }}</span></div>
                                            <div class="flex justify-between gap-1"><span class="text-zinc-400">OTP</span><span>{{ tuningRows[account.id].otp_tick_shots }}×{{ fmtInterval(tuningRows[account.id].otp_tick_interval_ms) }}</span></div>
                                            <div class="flex justify-between gap-1"><span class="text-zinc-400">Slot</span><span>{{ tuningRows[account.id].slot_tick_shots }}×{{ fmtInterval(tuningRows[account.id].slot_tick_interval_ms) }}</span></div>
                                            <div class="flex justify-between gap-1"><span class="text-zinc-400">Pay</span><span>{{ tuningRows[account.id].payment_tick_shots }}×{{ fmtInterval(tuningRows[account.id].payment_tick_interval_ms) }}</span></div>
                                        </div>
                                        <component :is="expandedTuningId === account.id ? ChevronUp : ChevronDown" class="h-3 w-3 shrink-0 text-zinc-400" />
                                    </button>
                                </TableCell>
                                <TableCell v-if="showAdminColumns" class="px-1 py-1 text-[10px] whitespace-nowrap border-l border-r border-zinc-200 dark:border-zinc-700 text-center">
                                    <select
                                        :value="account.status"
                                        class="h-6 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[9px]"
                                        @change="updateAccountStatus(account, ($event.target as HTMLSelectElement).value as any)"
                                    >
                                        <option value="running">Running</option>
                                        <option value="completed">Completed</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </TableCell>
                                <TableCell v-if="showAdminColumns" class="px-1 py-1 text-[10px] whitespace-nowrap text-center border-r border-zinc-200 dark:border-zinc-700">
                                    <div class="flex items-center justify-center gap-1.5" title="Toggle account active/inactive">
                                        <Switch :model-value="account.is_active"
                                            @update:model-value="(val: boolean) => updateStatus(account, val)" />
                                        <span :class="account.is_active ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400'" class="text-[9px] font-semibold">
                                            {{ account.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell class="px-1 py-1 text-[10px] whitespace-nowrap text-center border-r border-zinc-200 dark:border-zinc-700">
                                    <template v-if="showApplicationIds">
                                        <div v-if="applicationIdPdfs(account).length" class="flex flex-col items-center gap-px">
                                            <button v-for="pdf in applicationIdPdfs(account)" :key="pdf.id"
                                                type="button" @click="openApplicationIdPdf(account, pdf)"
                                                :disabled="openingPdfId !== null"
                                                class="font-mono text-[10px] font-bold leading-tight text-zinc-900 dark:text-zinc-100 hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors disabled:opacity-50"
                                                :title="`Open ${pdf.name}`">
                                                {{ pdf.application_id }}<span v-if="openingPdfId === pdf.id" class="ml-1 font-normal text-zinc-400">…</span>
                                            </button>
                                        </div>
                                        <span v-else class="inline-block px-1.5 py-0.5 rounded-none bg-red-600 dark:bg-red-700 text-white text-[9px] font-semibold">
                                            no pdf attached
                                        </span>
                                    </template>
                                    <div v-else class="flex items-center justify-center gap-1.5">
                                        <button v-if="getPdfAttachmentStatus(account).hasAttachment" type="button"
                                            @click="openPdfListDialog(account)"
                                            class="inline-block px-1.5 py-0.5 rounded bg-emerald-100 text-black text-[9px] font-semibold hover:bg-emerald-200 dark:hover:bg-emerald-200/80 transition-colors"
                                            title="View attached PDFs">
                                            {{ getPdfAttachmentStatus(account).text }}
                                        </button>
                                        <span v-else class="inline-block px-1.5 py-0.5 rounded-none bg-red-600 dark:bg-red-700 text-white text-[9px] font-semibold">
                                            {{ getPdfAttachmentStatus(account).text }}
                                        </span>
                                        <div class="flex items-center gap-1" title="Manually mark PDF upload done/pending — the bot skips upload once done">
                                            <Switch :model-value="!!account.pdf_uploaded"
                                                @update:model-value="(val: boolean) => updatePdfUploaded(account, val)" />
                                            <span class="text-[9px] text-zinc-400">Uploaded</span>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell v-if="showAdminColumns" class="px-1 py-1 text-[10px] whitespace-nowrap text-center border-r border-zinc-200 dark:border-zinc-700">
                                    <div class="flex items-center justify-center gap-1.5" title="Manually mark booking config done/pending — the bot skips this step once done">
                                        <Switch :model-value="!!account.booking_configured"
                                            @update:model-value="(val: boolean) => updateBookingConfigured(account, val)" />
                                        <span :class="account.booking_configured ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400'" class="text-[9px] font-semibold">
                                            {{ account.booking_configured ? 'Done' : 'Pending' }}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell class="px-1 py-1 text-[10px] whitespace-nowrap text-center">
                                    <template v-if="isSuperAdmin && userOptions.length > 0">
                                        <select
                                            :value="account.user?.id"
                                            class="h-6 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[9px] max-w-[7.5rem]"
                                            @change="changeOwner(account, Number(($event.target as HTMLSelectElement).value))"
                                        >
                                            <option v-for="u in userOptions" :key="u.id" :value="u.id">{{ u.name }}</option>
                                        </select>
                                    </template>
                                    <span v-else class="text-[10px]">{{ account.user ? account.user.name : '—' }}</span>
                                </TableCell>
                                <TableCell class="px-1 py-1 text-[10px] whitespace-nowrap text-center border-l border-zinc-200 dark:border-zinc-700">
                                    <div class="flex items-center justify-center gap-1.5" title="Auto-charge this account's booking payment once a slot is reserved">
                                        <Switch :model-value="!!account.auto_payment"
                                            @update:model-value="(val: boolean) => updateAutoPayment(account, val)" />
                                        <span v-if="account.auto_payment"
                                            class="rounded bg-emerald-100 px-1 py-0.5 text-[9px] font-semibold text-black dark:bg-emerald-900/40 dark:text-emerald-200">
                                            {{ AUTO_PAYMENT_METHODS.find(m => m.value === account.auto_payment_method)?.label ?? account.auto_payment_method ?? '—' }}
                                        </span>
                                        <span v-else class="text-[9px] text-zinc-400">Off</span>
                                    </div>
                                </TableCell>
                                <TableCell class="px-1 py-1 text-[10px] whitespace-nowrap text-center border-l border-r border-zinc-200 dark:border-zinc-700">
                                    <Button v-if="paymentLinkUrls[account.phone]" as="a" :href="paymentLinkUrls[account.phone]"
                                        target="_blank" rel="noopener noreferrer" size="sm" class="h-6 px-2 text-[10px] font-semibold">
                                        Pay Now
                                    </Button>
                                    <span v-else class="text-zinc-400">—</span>
                                </TableCell>
                                <TableCell class="px-1 py-1 text-[10px] whitespace-nowrap text-center border-r border-zinc-200 dark:border-zinc-700">
                                    <button v-if="invoicesByPhone[account.phone]" @click="downloadAccountInvoice(account)"
                                        class="inline-flex items-center gap-1 font-semibold disabled:opacity-50"
                                        :class="invoicesByPhone[account.phone].archived ? 'text-emerald-600 dark:text-emerald-400' : 'text-blue-600 dark:text-blue-400'"
                                        :title="invoicesByPhone[account.phone].archived ? 'Download invoice (archived)' : 'Download invoice (fetches from IVAC)'"
                                        :disabled="downloadingInvoicePhone === account.phone">
                                        <Loader2 v-if="downloadingInvoicePhone === account.phone" class="h-8 w-8 animate-spin" />
                                        <img v-else src="/images/pdf.png" alt="Invoice PDF" class="h-8 w-8"
                                            :class="invoicesByPhone[account.phone].archived ? '' : 'opacity-60'" />
                                    </button>
                                    <span v-else class="text-zinc-400">—</span>
                                </TableCell>
                                <TableCell class="pl-2 pr-3 py-1 text-center border-l">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="icon" class="h-6 w-6">
                                                <MoreVertical class="h-3.5 w-3.5" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" class="w-40">
                                            <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem :disabled="accountsLocked" @click="openEditDialog(account)">
                                                <Edit2 class="mr-2 h-4 w-4" /> Edit
                                            </DropdownMenuItem>
                                            <DropdownMenuItem :disabled="accountsLocked" @click="deleteAccount(account.id)" class="text-destructive">
                                                <Trash2 class="mr-2 h-4 w-4" /> Delete
                                            </DropdownMenuItem>
                                            <div v-if="accountsLocked" class="px-2 py-1 text-[10px] text-muted-foreground">
                                                Locked until {{ lockRangeLabel.split('\u2013')[1] || 'the window closes' }}
                                            </div>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="showAdminColumns && expandedTuningId === account.id && tuningRows[account.id]"
                                :class="idx % 2 === 0 ? 'bg-white dark:bg-zinc-950/20' : 'bg-zinc-100/70 dark:bg-zinc-800/30'"
                            >
                                <TableCell :colspan="desktopColumnCount" class="px-3 py-2 border-b border-zinc-200 dark:border-zinc-700">
                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                        <template v-for="phase in ([
                                            { key: 'signin',  label: 'Sign-in' },
                                            { key: 'otp',     label: 'OTP' },
                                            { key: 'slot',    label: 'Slot' },
                                            { key: 'payment', label: 'Payment' },
                                        ] as const)" :key="phase.key">
                                            <div class="rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-1.5 flex flex-col gap-1">
                                                <span class="text-[9px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ phase.label }}</span>
                                                <div class="flex items-center gap-1">
                                                    <input type="number" min="1"
                                                        v-model.number="(tuningRows[account.id] as any)[`${phase.key}_tick_shots`]"
                                                        @blur="onTuningBlur(account, `${phase.key}_tick_shots` as any, 1)"
                                                        @keydown.enter="($event.target as HTMLInputElement).blur()"
                                                        class="w-12 h-6 rounded border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-1 text-[9px] font-bold tabular-nums text-center focus:outline-none focus:ring-1 focus:ring-emerald-500/50 focus:border-emerald-400 dark:focus:border-emerald-600 transition-colors [appearance:textfield] [&::-webkit-outer-spin-button]:[-webkit-appearance:none] [&::-webkit-inner-spin-button]:[-webkit-appearance:none]"
                                                    />
                                                    <span class="text-[9px] text-zinc-400">×</span>
                                                    <input type="number" min="100" step="100"
                                                        v-model.number="(tuningRows[account.id] as any)[`${phase.key}_tick_interval_ms`]"
                                                        @blur="onTuningBlur(account, `${phase.key}_tick_interval_ms` as any, 100)"
                                                        @keydown.enter="($event.target as HTMLInputElement).blur()"
                                                        class="w-16 h-6 rounded border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-1 text-[9px] font-bold tabular-nums text-center focus:outline-none focus:ring-1 focus:ring-emerald-500/50 focus:border-emerald-400 dark:focus:border-emerald-600 transition-colors [appearance:textfield] [&::-webkit-outer-spin-button]:[-webkit-appearance:none] [&::-webkit-inner-spin-button]:[-webkit-appearance:none]"
                                                    />
                                                    <span class="text-[9px] text-zinc-400">ms</span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </TableCell>
                            </TableRow>
                            </template>
                            </template>
                        </TableBody>
                    </Table>
                </div>

                <DataTablePagination :meta="paginationMeta" @page-change="fetchAccounts" />
            </div>

            <!-- Create/Edit Panel (slides in from right) -->
            <Sheet v-model:open="isDialogOpen">
                <SheetContent side="right" class="w-full sm:max-w-lg overflow-y-auto p-0 flex flex-col">
                    <SheetHeader class="p-4 border-b border-zinc-200 dark:border-zinc-800 shrink-0">
                        <SheetTitle class="text-[14px] font-semibold">{{ editingAccount ? 'Update Account' : 'New Account' }}</SheetTitle>
                        <SheetDescription class="text-[11px]">
                            Enter credentials and operational parameters.
                        </SheetDescription>
                    </SheetHeader>
                    <div class="grid content-start gap-4 py-3 px-4 flex-1 overflow-y-auto">
                        <!-- Section: Account Credentials -->
                        <div class="grid gap-2">
                            <div class="flex items-center gap-1.5 pb-1.5 border-b border-zinc-200 dark:border-zinc-800">
                                <CircleUser class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Account Credentials</span>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="email" class="text-[11px] font-semibold">Email</Label>
                                <Input id="email" type="email" v-model="form.email" placeholder="user@example.com" class="h-8 text-[11px]" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="phone" class="text-[11px] font-semibold">Phone</Label>
                                <Input id="phone" v-model="form.phone" placeholder="017XXXXXXX" class="h-8 text-[11px]" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="password" class="text-[11px] font-semibold">Password</Label>
                                <Input id="password" type="text" v-model="form.password" placeholder="Password" class="h-8 text-[11px]" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="tag" class="text-[11px] font-semibold">Tag <span class="text-zinc-400 font-normal">(optional)</span></Label>
                                <Input id="tag" v-model="form.tag" placeholder="VIP, Test, etc." class="h-8 text-[11px]" />
                            </div>
                        </div>

                        <!-- Section: Appointment -->
                        <div v-if="!isAgent" class="grid gap-2">
                            <div class="flex items-center gap-1.5 pb-1.5 border-b border-zinc-200 dark:border-zinc-800">
                                <CalendarRange class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Appointment</span>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="appointment_id" class="text-[11px] font-semibold">Appointment ID <span class="text-zinc-400 font-normal">(optional)</span></Label>
                                <Input id="appointment_id" v-model="form.appointment_id" placeholder="Auto-fetched after sign-in if blank" class="h-8 text-[11px]" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label class="text-[11px] font-semibold">Date Range <span class="text-zinc-400 font-normal">(slot reserve — Fri/Sat excluded)</span></Label>
                                <DateRangePicker
                                    :model-value="{ from: form.appointment_from, to: form.appointment_to }"
                                    @update:model-value="(v) => { form.appointment_from = v.from; form.appointment_to = v.to; }"
                                    trigger-class="h-8 w-full text-[11px]"
                                />
                                <p class="text-[10px] text-muted-foreground">{{ expandDateRange(form.appointment_from, form.appointment_to).length }} date(s) selected</p>
                            </div>
                        </div>

                        <!-- Section: Booking Setup -->
                        <div class="grid gap-2">
                            <div class="flex items-center gap-1.5 pb-1.5 border-b border-zinc-200 dark:border-zinc-800">
                                <FileText class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Booking Setup</span>
                            </div>
                            <div class="grid gap-1.5">
                                <div class="flex items-center justify-between">
                                    <Label class="text-[11px] font-semibold">Booking Config — IVAC Centre</Label>
                                    <span v-if="editingAccount"
                                        :class="editingAccount.booking_configured ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'"
                                        class="text-[10px] font-semibold">
                                        {{ editingAccount.booking_configured ? 'Configured' : 'Not configured' }}
                                    </span>
                                </div>
                                <select v-model="form.booking_city"
                                    class="h-8 rounded border border-zinc-200 dark:border-zinc-800 bg-transparent px-2 text-[11px]">
                                    <option v-for="city in BOOKING_CITIES" :key="city" :value="city">{{ city }}</option>
                                </select>
                                <p class="text-[10px] text-muted-foreground">Sent to IVAC as booking config before slot reserve. Changing this re-triggers setup.</p>
                            </div>
                        </div>

                        <!-- Section: Auto Payment -->
                        <div class="grid gap-2">
                            <div class="flex items-center gap-1.5 pb-1.5 border-b border-zinc-200 dark:border-zinc-800">
                                <CreditCard class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Auto Payment</span>
                            </div>
                            <div class="flex items-center gap-2 rounded border border-zinc-200 dark:border-zinc-800 p-2 bg-zinc-50 dark:bg-zinc-900/30">
                                <Switch id="auto_payment" :model-value="form.auto_payment"
                                    @update:model-value="(v: boolean) => form.auto_payment = v" />
                                <div class="grid gap-0.5">
                                    <Label for="auto_payment" class="cursor-pointer text-[11px] font-semibold">Pay automatically</Label>
                                    <span class="text-[10px] text-zinc-400">When a payment link appears for this account, the portal completes the dg-epay checkout itself — no manual link click.</span>
                                </div>
                            </div>

                            <!-- Credentials are collected at the moment auto payment is switched on. -->
                            <div v-if="form.auto_payment" class="grid gap-2 rounded border border-emerald-200 dark:border-emerald-900/50 bg-emerald-50/50 dark:bg-emerald-950/20 p-2">
                                <div class="grid gap-1.5">
                                    <Label class="text-[11px] font-semibold">Payment Method</Label>
                                    <select v-model="form.auto_payment_method"
                                        class="h-8 rounded border border-zinc-200 dark:border-zinc-800 bg-transparent px-2 text-[11px]">
                                        <option v-for="m in AUTO_PAYMENT_METHODS" :key="m.value" :value="m.value" :disabled="!m.supported">
                                            {{ m.label }}{{ m.supported ? '' : ' — not available yet' }}
                                        </option>
                                    </select>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label class="text-[11px] font-semibold">Saved Wallet</Label>
                                    <select :value="selectedWalletId"
                                        @change="onSelectWallet(($event.target as HTMLSelectElement).value)"
                                        class="h-8 rounded border border-zinc-200 dark:border-zinc-800 bg-transparent px-2 text-[11px]">
                                        <option value="">Enter manually</option>
                                        <option v-for="w in walletOptionsForMethod" :key="w.id" :value="w.id">
                                            {{ w.label ? `${w.label} — ` : '' }}{{ w.wallet_number }}
                                        </option>
                                        <option value="new">+ Add new wallet…</option>
                                    </select>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="auto_payment_wallet" class="text-[11px] font-semibold">Wallet Number</Label>
                                    <Input id="auto_payment_wallet" v-model="form.auto_payment_wallet" class="h-8 text-[11px]"
                                        :maxlength="form.auto_payment_method === 'rocket' ? 12 : 20"
                                        placeholder="01XXXXXXXXX" autocomplete="off" />
                                    <p v-if="form.auto_payment_method === 'rocket'" class="text-[9px] text-zinc-400">Rocket wallet numbers are exactly 12 digits.</p>
                                    <p class="text-[9px] text-zinc-400">The paying wallet. Its SIM must be on an SMS forwarder so the payment OTP reaches the portal.</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="auto_payment_pin" class="text-[11px] font-semibold">PIN</Label>
                                    <div class="relative">
                                        <Input id="auto_payment_pin" :type="showAutoPaymentPin ? 'text' : 'password'"
                                            v-model="form.auto_payment_pin" class="h-8 text-[11px] pr-8"
                                            placeholder="Wallet PIN" autocomplete="new-password" />
                                        <button type="button" tabindex="-1" @click="showAutoPaymentPin = !showAutoPaymentPin"
                                            class="absolute inset-y-0 right-0 flex items-center px-2 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                            <EyeOff v-if="showAutoPaymentPin" class="h-3.5 w-3.5" />
                                            <Eye v-else class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                    <p class="text-[9px] text-zinc-400">Stored encrypted. Leave blank when editing to keep the saved PIN.</p>
                                </div>
                                <div v-if="selectedWalletId === 'new'" class="grid gap-1.5 rounded border border-emerald-300 dark:border-emerald-800 bg-white dark:bg-zinc-950 p-2">
                                    <Label for="new_wallet_label" class="text-[11px] font-semibold">Save as Wallet — Label <span class="text-zinc-400 font-normal">(optional)</span></Label>
                                    <Input id="new_wallet_label" v-model="newWalletLabel" placeholder="e.g. Personal" class="h-8 text-[11px]" />
                                    <Button type="button" size="sm" :disabled="savingNewWallet" @click="saveNewWallet" class="h-7 text-[11px] bg-emerald-600 hover:bg-emerald-700 text-white">
                                        {{ savingNewWallet ? 'Saving…' : 'Save to Wallet' }}
                                    </Button>
                                    <p class="text-[9px] text-zinc-400">Saves the number/PIN above so it can be picked next time, and shows up on your Wallet page.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Status & Behavior -->
                        <div class="grid gap-2">
                            <div class="flex items-center gap-1.5 pb-1.5 border-b border-zinc-200 dark:border-zinc-800">
                                <ToggleLeft class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Status & Behavior</span>
                            </div>
                            <div class="flex items-center gap-2 rounded border border-zinc-200 dark:border-zinc-800 p-2 bg-zinc-50 dark:bg-zinc-900/30">
                                <Switch id="is_active" :model-value="form.is_active"
                                    @update:model-value="(v: boolean) => form.is_active = v" />
                                <Label for="is_active" class="cursor-pointer text-[11px] font-semibold">Active</Label>
                            </div>

                            <div v-if="canManageRaceSettings" class="flex items-center gap-2 rounded border border-zinc-200 dark:border-zinc-800 p-2 bg-zinc-50 dark:bg-zinc-900/30">
                                <Switch id="single_sign_in" :model-value="form.single_sign_in"
                                    @update:model-value="(v: boolean) => form.single_sign_in = v" />
                                <div class="grid gap-0.5">
                                    <Label for="single_sign_in" class="cursor-pointer text-[11px] font-semibold">Single sign-in</Label>
                                    <span class="text-[10px] text-zinc-400">Sequential sign-in — one call at a time, wait for the result before retrying (avoids duplicate OTPs).</span>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Retry Settings -->
                        <div v-if="canManageRaceSettings" class="grid gap-2">
                            <div class="flex items-center gap-1.5 pb-1.5 border-b border-zinc-200 dark:border-zinc-800">
                                <RotateCcw class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Retry Settings</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div class="grid gap-1.5">
                                    <Label for="max_retries" class="text-[11px] font-semibold">Max Retries</Label>
                                    <Input id="max_retries" type="number" v-model="form.max_retries" class="h-8 text-[11px]" />
                                    <p class="text-[9px] text-zinc-400">Retry count</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="retry_delay" class="text-[11px] font-semibold">Retry Delay</Label>
                                    <Input id="retry_delay" type="number" v-model="form.retry_delay_ms" class="h-8 text-[11px]" placeholder="ms" />
                                    <p class="text-[9px] text-zinc-400">Delay (ms)</p>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Race Tuning -->
                        <div v-if="canManageRaceSettings" class="grid gap-2">
                            <div class="flex items-center gap-1.5 pb-1.5 border-b border-zinc-200 dark:border-zinc-800">
                                <Zap class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Race Tuning</span>
                            </div>
                            <div class="grid gap-x-2 gap-y-1.5 rounded border border-zinc-200 dark:border-zinc-800 p-2" style="grid-template-columns: 4.5rem 1fr 1fr">
                                <span class="text-[9px] text-zinc-400 uppercase font-semibold"></span>
                                <span class="text-[9px] text-zinc-400 uppercase font-semibold text-center">Shots / Tick</span>
                                <span class="text-[9px] text-zinc-400 uppercase font-semibold text-center">Interval (ms)</span>

                                <span class="text-[11px] text-zinc-500 self-center font-medium">Sign-in</span>
                                <Input id="signin_tick_shots" type="number" min="1" v-model.number="form.signin_tick_shots" class="h-8 text-[11px] text-center" placeholder="1" />
                                <Input id="signin_tick_interval_ms" type="number" min="100" step="100" v-model.number="form.signin_tick_interval_ms" class="h-8 text-[11px] text-center" placeholder="21000" />

                                <span class="text-[11px] text-zinc-500 self-center font-medium">OTP</span>
                                <Input id="otp_tick_shots" type="number" min="1" v-model.number="form.otp_tick_shots" class="h-8 text-[11px] text-center" placeholder="1" />
                                <Input id="otp_tick_interval_ms" type="number" min="100" step="100" v-model.number="form.otp_tick_interval_ms" class="h-8 text-[11px] text-center" placeholder="21000" />

                                <span class="text-[11px] text-zinc-500 self-center font-medium">Slot</span>
                                <Input id="slot_tick_shots" type="number" min="1" v-model.number="form.slot_tick_shots" class="h-8 text-[11px] text-center" placeholder="1" />
                                <Input id="slot_tick_interval_ms" type="number" min="100" step="100" v-model.number="form.slot_tick_interval_ms" class="h-8 text-[11px] text-center" placeholder="21000" />

                                <span class="text-[11px] text-zinc-500 self-center font-medium">Payment</span>
                                <Input id="payment_tick_shots" type="number" min="1" v-model.number="form.payment_tick_shots" class="h-8 text-[11px] text-center" placeholder="1" />
                                <Input id="payment_tick_interval_ms" type="number" min="100" step="100" v-model.number="form.payment_tick_interval_ms" class="h-8 text-[11px] text-center" placeholder="21000" />
                            </div>
                            <p class="text-[9px] text-zinc-400">Shots per tick and ms between ticks, per race phase (defaults: 1 shot / 21000ms).</p>
                        </div>

                        <!-- Section: Applicant PDFs -->
                        <div class="grid gap-2">
                            <div class="flex items-center justify-between pb-1.5 border-b border-zinc-200 dark:border-zinc-800">
                                <div class="flex items-center gap-1.5">
                                    <FileText class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                                    <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Applicant PDFs</span>
                                    <span class="text-[10px] text-zinc-400">(one must be primary)</span>
                                </div>
                                <span v-if="editingAccount"
                                    :class="editingAccount.pdf_uploaded ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'"
                                    class="text-[10px] font-semibold">
                                    {{ editingAccount.pdf_uploaded ? 'Uploaded' : 'Not uploaded' }}
                                </span>
                            </div>
                            <input ref="pdfInput" type="file" multiple accept="application/pdf,.pdf"
                                class="hidden" @change="onPdfFilesSelected" />
                            <button type="button" @click="pdfInput?.click()" :disabled="pdfChecking"
                                class="h-8 rounded border border-dashed border-zinc-300 dark:border-zinc-700 text-[11px] text-muted-foreground hover:bg-zinc-50 dark:hover:bg-zinc-900/30 disabled:opacity-60">
                                {{ pdfChecking ? 'Checking registration date…' : '+ Add PDF(s)' }}
                            </button>
                            <div v-if="form.pdfs.length" class="grid gap-1">
                                <div v-for="(pdf, index) in form.pdfs" :key="index"
                                    class="flex items-center gap-2 h-11 rounded border px-2.5"
                                    :class="pdf.is_primary
                                        ? 'border-emerald-300 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-950/20'
                                        : 'border-zinc-200 dark:border-zinc-800'">
                                    <FileText class="h-5 w-5 shrink-0 text-red-500 dark:text-red-400" />
                                    <span class="flex-1 truncate text-[11px]" :title="pdf.name">{{ pdf.name }}</span>
                                    <span v-if="pdf.web_registration_date" class="shrink-0 text-[10px] text-muted-foreground"
                                        :title="'Web registration date'">{{ pdf.web_registration_date }}</span>
                                    <label class="flex items-center gap-1 cursor-pointer shrink-0" :title="'Mark as primary'">
                                        <input type="radio" :checked="pdf.is_primary" @change="setPrimaryPdf(index)"
                                            class="h-3 w-3 accent-emerald-600" />
                                        <span class="text-[10px]" :class="pdf.is_primary ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-zinc-400'">Primary</span>
                                    </label>
                                    <button type="button" @click="removePdf(index)"
                                        class="shrink-0 text-[11px] text-red-500 hover:text-red-600">Remove</button>
                                </div>
                            </div>
                            <p v-else class="text-[10px] text-muted-foreground">No PDFs added. Slot reserve fails until PDFs are uploaded.</p>
                        </div>

                        <div v-if="formErrors.length > 0" class="rounded border border-red-200 dark:border-red-900/40 bg-red-50 dark:bg-red-950/20 px-3 py-2">
                            <p v-for="(err, i) in formErrors" :key="i" class="text-[10px] text-red-700 dark:text-red-400">{{ err }}</p>
                        </div>
                    </div>
                    <SheetFooter class="p-4 border-t border-zinc-200 dark:border-zinc-800 shrink-0 gap-2">
                        <Button variant="outline" @click="isDialogOpen = false" :disabled="savingAccount" class="h-8 text-[11px]">Cancel</Button>
                        <Button @click="saveAccount" :disabled="(editingAccount != null && pdfsLoading) || savingAccount" class="h-8 text-[11px] bg-emerald-600 hover:bg-emerald-700 text-white">
                            <Loader2 v-if="savingAccount" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                            {{ savingAccount ? (editingAccount ? 'Saving…' : 'Creating…') : (editingAccount && pdfsLoading ? 'Loading…' : (editingAccount ? 'Apply' : 'Create')) }}
                        </Button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>

            <!-- Quick Auto Payment setup — opens when a row's switch is flipped on but no wallet is configured yet -->
            <Dialog v-model:open="autoPaymentDialogOpen">
                <DialogContent class="sm:max-w-[25rem] p-0 flex flex-col">
                    <DialogHeader class="p-3 border-b border-zinc-200 dark:border-zinc-800">
                        <DialogTitle class="text-[14px] font-semibold">Configure Auto Payment</DialogTitle>
                        <DialogDescription class="text-[11px]">
                            {{ autoPaymentDialogAccount?.phone }} has no wallet configured yet. Pick a saved wallet or add a new one.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-1.5 py-3 px-3">
                        <div class="grid gap-1.5">
                            <Label class="text-[11px] font-semibold">Payment Method</Label>
                            <select v-model="autoPaymentForm.method"
                                class="h-8 rounded border border-zinc-200 dark:border-zinc-800 bg-transparent px-2 text-[11px]">
                                <option v-for="m in AUTO_PAYMENT_METHODS" :key="m.value" :value="m.value" :disabled="!m.supported">
                                    {{ m.label }}{{ m.supported ? '' : ' — not available yet' }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label class="text-[11px] font-semibold">Saved Wallet</Label>
                            <select :value="autoPaymentSelectedWalletId"
                                @change="onSelectAutoPaymentWallet(($event.target as HTMLSelectElement).value)"
                                class="h-8 rounded border border-zinc-200 dark:border-zinc-800 bg-transparent px-2 text-[11px]">
                                <option value="">Enter manually</option>
                                <option v-for="w in autoPaymentWalletOptionsForMethod" :key="w.id" :value="w.id">
                                    {{ w.label ? `${w.label} — ` : '' }}{{ w.wallet_number }}
                                </option>
                                <option value="new">+ Add new wallet…</option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="quick_wallet_number" class="text-[11px] font-semibold">Wallet Number</Label>
                            <Input id="quick_wallet_number" v-model="autoPaymentForm.wallet_number" class="h-8 text-[11px]"
                                :maxlength="autoPaymentForm.method === 'rocket' ? 12 : 20"
                                placeholder="01XXXXXXXXX" autocomplete="off" />
                            <p v-if="autoPaymentForm.method === 'rocket'" class="text-[9px] text-zinc-400">Rocket wallet numbers are exactly 12 digits.</p>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="quick_wallet_pin" class="text-[11px] font-semibold">PIN</Label>
                            <div class="relative">
                                <Input id="quick_wallet_pin" :type="showQuickWalletPin ? 'text' : 'password'"
                                    v-model="autoPaymentForm.pin" class="h-8 text-[11px] pr-8"
                                    placeholder="Wallet PIN" autocomplete="new-password" />
                                <button type="button" tabindex="-1" @click="showQuickWalletPin = !showQuickWalletPin"
                                    class="absolute inset-y-0 right-0 flex items-center px-2 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                    <EyeOff v-if="showQuickWalletPin" class="h-3.5 w-3.5" />
                                    <Eye v-else class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                        <div v-if="autoPaymentSelectedWalletId === '' || autoPaymentSelectedWalletId === 'new'" class="grid gap-1.5">
                            <Label for="quick_wallet_label" class="text-[11px] font-semibold">Label <span class="text-zinc-400 font-normal">(optional)</span></Label>
                            <Input id="quick_wallet_label" v-model="autoPaymentForm.label" placeholder="e.g. Personal" class="h-8 text-[11px]" />
                            <p class="text-[9px] text-zinc-400">Saved to your Wallet page too, so it can be picked next time.</p>
                        </div>
                    </div>
                    <div class="p-3 border-t border-zinc-200 dark:border-zinc-800 flex gap-2">
                        <Button variant="outline" @click="autoPaymentDialogOpen = false" :disabled="autoPaymentSaving" class="h-8 text-[11px]">Cancel</Button>
                        <Button @click="confirmAutoPaymentDialog" :disabled="autoPaymentSaving" class="h-8 text-[11px] bg-emerald-600 hover:bg-emerald-700 text-white">
                            {{ autoPaymentSaving ? 'Enabling…' : 'Enable Auto Payment' }}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>

            <!-- Attachment count click — lists an account's PDFs; each opens in a new tab -->
            <Dialog v-model:open="pdfListDialogOpen">
                <DialogContent class="sm:max-w-[25rem] p-0 flex flex-col">
                    <DialogHeader class="p-3 border-b border-zinc-200 dark:border-zinc-800">
                        <DialogTitle class="text-[14px] font-semibold">Attached PDFs</DialogTitle>
                        <DialogDescription class="text-[11px]">{{ pdfListPhone }}</DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-1.5 py-3 px-3 max-h-[60vh] overflow-y-auto">
                        <div v-if="pdfListLoading" class="flex flex-col gap-1.5">
                            <div v-for="i in 2" :key="i" class="h-9 w-full animate-pulse rounded bg-muted"></div>
                        </div>
                        <p v-else-if="pdfListPdfs.length === 0" class="text-[11px] text-muted-foreground py-4 text-center">
                            No PDFs attached.
                        </p>
                        <button v-else v-for="(pdf, i) in pdfListPdfs" :key="i" type="button"
                            @click="openPdfInNewTab(pdf)"
                            class="flex items-center gap-2 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2.5 py-2 text-left hover:border-emerald-400 dark:hover:border-emerald-600 hover:bg-emerald-50/50 dark:hover:bg-emerald-950/20 transition-colors">
                            <FileText class="h-3.5 w-3.5 shrink-0 text-zinc-400" />
                            <div class="flex-1 min-w-0">
                                <div class="text-[11px] font-medium truncate">{{ pdf.name }}</div>
                                <div class="text-[9px] text-zinc-400 tabular-nums">{{ pdfDisplaySize(pdf) }}</div>
                            </div>
                            <span v-if="pdf.is_primary" class="shrink-0 rounded bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400 px-1 py-px text-[9px] font-bold uppercase tracking-wide">Primary</span>
                            <ExternalLink class="h-3.5 w-3.5 shrink-0 text-zinc-400" />
                        </button>
                    </div>
                    <div class="p-3 border-t border-zinc-200 dark:border-zinc-800">
                        <Button variant="outline" @click="pdfListDialogOpen = false" class="h-8 text-[11px] w-full">Close</Button>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
