<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { CheckCircle2, Circle, Copy, Trash2, X, RotateCw, Send, Loader2, Link, FileText, ShieldCheck, Download, Pencil, CreditCard, ArrowUp, ArrowDown, ExternalLink, ScrollText } from 'lucide-vue-next';
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
import DataTablePagination from '@/components/DataTablePagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';

interface PaymentLink {
    id: number;
    account_phone: string | null;
    reservation_id: string | null;
    gateway_page_url: string | null;
    redirect_gateway_url: string | null;
    callback_url: string | null;
    callback_status: string | null;
    callback_status_code: number | null;
    callback_response: string | null;
    status_code: number | null;
    message: string | null;
    success_flag: boolean | null;
    is_clicked: boolean;
    review_status: 'unread' | 'declined' | 'succeeded';
    invoice_path?: string | null;
    invoice_fetched_at?: string | null;
    server_time: string | null;
    created_at: string;
    updated_at: string;
}

type ReviewStatus = 'unread' | 'declined' | 'succeeded';

interface GroupedPaymentLink extends PaymentLink {
    links_count: number;
    all_links: PaymentLink[];
    group_date: string;
    latest_at: string;
    jwt_expires_at: string | null;
    account?: { id: number; phone: string; tag: string; status?: 'running' | 'completed' | 'cancelled'; user?: { name: string } };
    visa_type: string | null;
    application_id: string | null;
    number_of_applicants: number | null;
    total_amount: number | null;
}

interface Account {
    id: number;
    phone: string;
    tag: string;
    booking_city?: string | null;
    status?: 'running' | 'completed' | 'cancelled';
    user?: { name: string };
}

interface BypassIpOption {
    id: number;
    label: string;
    ip: string;
    is_default: boolean;
}

const bypassIps = ref<BypassIpOption[]>([]);
const bypassIpId = ref<number | null | 'all'>('all');

const paymentLinks = ref<GroupedPaymentLink[]>([]);
const paginationMeta = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    from: 0,
    to: 0,
});
const loading = ref(true);
const accounts = ref<Account[]>([]);

const modalOpen = ref(false);
const modalLinks = ref<PaymentLink[]>([]);
const modalPhone = ref<string | null>(null);

// Filter state
function getTodayDate(): string {
    const today = new Date();
    return today.toISOString().split('T')[0];
}

function getInitialDate(param: string): string {
    const url = new URL(window.location.href);
    return url.searchParams.get(param) ?? getTodayDate();
}

/** Read a filter's starting value from the URL so a reload restores the same filtered view. */
function getInitialParam(param: string, fallback = ''): string {
    return new URL(window.location.href).searchParams.get(param) ?? fallback;
}

const fromDate = ref<string>(getInitialDate('from_date'));
const toDate = ref<string>(getInitialDate('to_date'));
const searchPhone = ref<string>(getInitialParam('phone'));
const visaTypeFilter = ref<string>(getInitialParam('visa_type'));
const reviewStatusFilter = ref<string>(getInitialParam('review_status'));
const callbackStatusFilter = ref<string>(getInitialParam('callback_status'));
const clickedFilter = ref<string>(getInitialParam('clicked'));
// Links land here the moment the bot initiates a payment and expire 5 minutes later, so the
// page follows them on its own rather than waiting to be told to.
const autoRefresh = ref<boolean>(true);
let refreshInterval: NodeJS.Timeout | null = null;

/**
 * Rows already on screen, keyed by grouped-row id with the link count that produced them.
 * A background refresh compares against this so a brand-new group — or an extra link inside
 * an existing group — can slide in instead of the whole table blinking through the skeleton.
 */
const seenRowSignatures = new Map<number, number>();
const newRowIds = ref<Set<number>>(new Set());
const newRowTimers = new Map<number, ReturnType<typeof setTimeout>>();
const NEW_ROW_HIGHLIGHT_MS = 4000;

const rememberRows = (rows: GroupedPaymentLink[]) => {
    seenRowSignatures.clear();
    for (const row of rows) {
        seenRowSignatures.set(row.id, row.links_count);
    }
};

const flagNewRows = (rows: GroupedPaymentLink[]) => {
    const fresh = new Set(newRowIds.value);

    for (const row of rows) {
        const previousCount = seenRowSignatures.get(row.id);
        if (previousCount !== undefined && previousCount >= row.links_count) {
            continue;
        }

        fresh.add(row.id);

        const existingTimer = newRowTimers.get(row.id);
        if (existingTimer) {
            clearTimeout(existingTimer);
        }
        newRowTimers.set(row.id, setTimeout(() => {
            const remaining = new Set(newRowIds.value);
            remaining.delete(row.id);
            newRowIds.value = remaining;
            newRowTimers.delete(row.id);
        }, NEW_ROW_HIGHLIGHT_MS));
    }

    newRowIds.value = fresh;
    rememberRows(rows);
};

const isNewRow = (link: GroupedPaymentLink): boolean => newRowIds.value.has(link.id);

const clearNewRowState = () => {
    for (const timer of newRowTimers.values()) {
        clearTimeout(timer);
    }
    newRowTimers.clear();
    if (newRowIds.value.size > 0) {
        newRowIds.value = new Set();
    }
};

/**
 * Builds the query params for the current filter state. Shared between the actual fetch and
 * the URL sync below so the two can never drift apart.
 */
const buildFilterParams = (page: number): Record<string, string | number> => {
    const params: Record<string, string | number> = { page, per_page: 100 };
    if (fromDate.value) params.from_date = fromDate.value;
    if (toDate.value) params.to_date = toDate.value;
    if (searchPhone.value.trim()) params.phone = searchPhone.value.trim();
    if (visaTypeFilter.value) params.visa_type = visaTypeFilter.value;
    if (reviewStatusFilter.value) params.review_status = reviewStatusFilter.value;
    if (callbackStatusFilter.value) params.callback_status = callbackStatusFilter.value;
    if (clickedFilter.value) params.clicked = clickedFilter.value;
    return params;
};

/**
 * Mirrors every active filter (server-side and client-side) into the URL query string via
 * replaceState, so a reload — or a shared link — lands back on the same filtered view instead
 * of resetting to today's unfiltered list. replaceState (not pushState) keeps the back button
 * from filling up with one entry per keystroke/filter tweak.
 */
const updateUrlState = () => {
    const params = buildFilterParams(paginationMeta.value.current_page);
    const qs = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (key === 'per_page') continue;
        if (key === 'page' && value === 1) continue;
        qs.set(key, String(value));
    }
    if (groupBy.value !== 'none') qs.set('group_by', groupBy.value);
    if (agentFilter.value) qs.set('agent', agentFilter.value);

    const search = qs.toString();
    window.history.replaceState(null, '', window.location.pathname + (search ? `?${search}` : ''));
};

const fetchPaymentLinks = async (page = 1, background = false) => {
    if (!background) {
        loading.value = true;
    }
    try {
        const params = buildFilterParams(page);

        const response = await axios.get('/api/payment-links', { params });
        paymentLinks.value = response.data.data;
        if (background) {
            flagNewRows(paymentLinks.value);
        } else {
            clearNewRowState();
            rememberRows(paymentLinks.value);
        }
        paginationMeta.value = {
            current_page: response.data.current_page,
            last_page: response.data.last_page,
            total: response.data.total,
            from: response.data.from ?? 0,
            to: response.data.to ?? 0,
        };
        updateUrlState();
    } catch (error) {
        console.error('Failed to fetch payment links', error);
    } finally {
        loading.value = false;
    }
};

const fetchAccounts = async () => {
    try {
        const response = await axios.get('/api/accounts', { params: { per_page: 500, status: 'all' } });
        accounts.value = response.data.data || [];
    } catch (error) {
        console.error('Failed to fetch accounts', error);
    }
};

/** O(1) account lookup keyed by id, rebuilt only when the accounts list changes. */
const accountsById = computed(() => {
    const map = new Map<number, Account>();
    for (const account of accounts.value) {
        map.set(account.id, account);
    }
    return map;
});

const findAccount = (link: GroupedPaymentLink): Account | undefined => {
    const id = link.account?.id;
    return id === undefined ? undefined : accountsById.value.get(id);
};

const getAccountInfo = (link: GroupedPaymentLink): string => {
    const account = findAccount(link);
    return account?.phone ?? link.account_phone ?? '—';
};

// The separately-fetched account list is preferred so the badge follows a status change without a
// reload, falling back to the copy embedded in the link row when that list has not arrived yet.
const isAccountCompleted = (link: GroupedPaymentLink): boolean => {
    return (findAccount(link)?.status ?? link.account?.status) === 'completed';
};

const getAccountTag = (link: GroupedPaymentLink): string => {
    const account = findAccount(link);
    return account?.tag || account?.phone || '';
};

const getAccountCenter = (link: GroupedPaymentLink): string => {
    const account = findAccount(link);
    return account?.booking_city || '';
};

const getAccountUserName = (link: GroupedPaymentLink): string => {
    return link.account?.user?.name ?? '';
};

const applyFilters = () => {
    fetchPaymentLinks(1);
};

const resetFilters = () => {
    fromDate.value = getTodayDate();
    toDate.value = getTodayDate();
    searchPhone.value = '';
    visaTypeFilter.value = '';
    reviewStatusFilter.value = '';
    callbackStatusFilter.value = '';
    clickedFilter.value = '';
    fetchPaymentLinks(1);
};

let searchDebounce: ReturnType<typeof setTimeout>;
const onSearchInput = () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => fetchPaymentLinks(1), 400);
};

const startAutoRefresh = () => {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }

    refreshInterval = setInterval(() => {
        fetchPaymentLinks(paginationMeta.value.current_page, true);
    }, 5000);
};

const toggleAutoRefresh = () => {
    autoRefresh.value = !autoRefresh.value;

    if (autoRefresh.value) {
        startAutoRefresh();
    } else {
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
        clearNewRowState();
    }
};

const DHAKA_TZ = 'Asia/Dhaka';

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString([], { timeZone: DHAKA_TZ }) + ' ' + new Date(date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: DHAKA_TZ });
};

const copyLink = async (url: string, link?: PaymentLink) => {
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
    } catch (e) {
        console.error('Copy failed', e);
    }
    if (link) {
        await markClicked(link);
    }
};

const markClicked = async (link: PaymentLink) => {
    if (link.is_clicked) return;
    try {
        await axios.patch(`/api/payment-links/${link.id}/clicked`);
        link.is_clicked = true;
    } catch (e) {
        console.error('Failed to mark as clicked', e);
    }
};

const openLink = async (link: PaymentLink, url: string) => {
    await markClicked(link);
    // Also update the parent grouped row if this link is the first one
    const parent = paymentLinks.value.find(g => g.id === link.id);
    if (parent) {
        parent.is_clicked = true;
    }
    window.open(url, '_blank', 'noopener,noreferrer');
};

const formatGroupDate = (date: string) => {
    return new Date(date + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
};

/**
 * Auto payment log for one link. A grouped row shows the newest link's log, which is the run an
 * operator is almost always asking about.
 */
const automationLogUrl = (link: PaymentLink | GroupedPaymentLink): string => {
    const target = (link as GroupedPaymentLink).all_links?.[0] ?? link;

    return `/payment-links/${target.id}/automation-log`;
};

const openModal = (link: GroupedPaymentLink) => {
    const account = accounts.value.find(a => a.id === link.account?.id);
    const name = account ? `${account.tag || account.phone} (${account.phone})` : link.account_phone;
    modalPhone.value = `${name} — ${formatGroupDate(link.group_date)}`;
    modalLinks.value = link.all_links;
    modalOpen.value = true;
};


const deleteLink = async (link: GroupedPaymentLink) => {
    if (!confirm('Are you sure you want to delete this payment link?')) return;
    try {
        await axios.delete(`/api/payment-links/${link.id}`);
        await fetchPaymentLinks(paginationMeta.value.current_page);
    } catch (error) {
        console.error('Failed to delete payment link', error);
    }
};

/** Whether any link in the group (not just the group's own base record) was clicked. */
const groupIsClicked = (link: GroupedPaymentLink): boolean => {
    return link.all_links?.some(ml => ml.is_clicked) ?? link.is_clicked;
};

const rowBgClasses = (link: GroupedPaymentLink): string => {
    if (link.review_status === 'declined') return 'bg-red-50/70 dark:bg-red-950/15 hover:bg-red-50 dark:hover:bg-red-950/25';
    if (link.review_status === 'succeeded') return 'bg-emerald-50/70 dark:bg-emerald-950/15 hover:bg-emerald-50 dark:hover:bg-emerald-950/25';
    if (groupIsClicked(link)) return 'bg-amber-100/70 dark:bg-amber-950/25 hover:bg-amber-100 dark:hover:bg-amber-950/40';
    return 'hover:bg-zinc-50 dark:hover:bg-zinc-900/60';
};

const reviewStatusLabel = (status: ReviewStatus): string => {
    if (status === 'declined') return 'Declined';
    if (status === 'succeeded') return 'Paid';
    return 'Unread';
};

const updatingReviewStatus = ref<Record<number, boolean>>({});

const setReviewStatus = async (link: GroupedPaymentLink, status: ReviewStatus) => {
    if (link.review_status === status) return;
    updatingReviewStatus.value[link.id] = true;
    try {
        await axios.patch(`/api/payment-links/${link.id}/review-status`, { review_status: status });
        link.review_status = status;
        const inner = link.all_links.find(l => l.id === link.id);
        if (inner) { inner.review_status = status; }
    } catch (e) {
        console.error('Failed to update review status', e);
    } finally {
        delete updatingReviewStatus.value[link.id];
    }
};

// Visa type / applicant count editing — updates the underlying account_sessions row,
// the same source the monthly report reads from, so both stay in sync.
const visaTypesList = ref<string[]>([]);
const visaEditDialogOpen = ref(false);
const visaEditTarget = ref<GroupedPaymentLink | null>(null);
const visaEditForm = ref<{ visa_type: string; number_of_applicants: number }>({ visa_type: '', number_of_applicants: 1 });
const visaEditSaving = ref(false);
const visaEditError = ref<string | null>(null);

const fetchVisaTypes = async () => {
    try {
        const res = await axios.get('/api/payment-links/visa-types');
        visaTypesList.value = res.data;
    } catch (e) {
        console.error('Failed to fetch visa types', e);
    }
};

const openVisaEdit = (link: GroupedPaymentLink) => {
    visaEditTarget.value = link;
    visaEditForm.value = {
        visa_type: link.visa_type ?? (visaTypesList.value[0] ?? ''),
        number_of_applicants: link.number_of_applicants ?? 1,
    };
    visaEditError.value = null;
    visaEditDialogOpen.value = true;
};

const saveVisaEdit = async () => {
    const target = visaEditTarget.value;
    if (!target) return;

    visaEditSaving.value = true;
    visaEditError.value = null;
    try {
        const res = await axios.patch(`/api/payment-links/${target.id}/visa-info`, visaEditForm.value);
        for (const item of paymentLinks.value) {
            if (item.account_phone === target.account_phone) {
                item.visa_type = res.data.visa_type;
                item.number_of_applicants = res.data.number_of_applicants;
            }
        }
        visaEditDialogOpen.value = false;
    } catch (e: any) {
        visaEditError.value = e?.response?.data?.message ?? 'Failed to save changes.';
    } finally {
        visaEditSaving.value = false;
    }
};

// Post-payment redirect URL submission — matched to a payment link by its tran_id.
const callbackDialogOpen = ref(false);
const callbackUrlInput = ref('');
const submittingCallback = ref(false);

const openCallbackDialog = () => {
    callbackUrlInput.value = '';
    callbackDialogOpen.value = true;
};

const callbackStatusColor = (link: PaymentLink): string => {
    const s = link.callback_status;
    if (s === 'success') return 'text-emerald-600 dark:text-emerald-400';
    if (s === 'failed') return 'text-red-500 dark:text-red-400';
    if (s === 'pending') return 'text-amber-500 dark:text-amber-400';
    return 'text-zinc-400';
};

// Shown for any row IVAC could hold an invoice against — i.e. anything with a reservation.
// It deliberately does NOT require callback_status 'success': that flag only means IVAC accepted
// our callback GET, so gating on it hid the button on rows that were in fact paid. The archive
// serves a banked copy instantly and only otherwise asks IVAC, so an attempt on a row with no
// invoice costs one failed fetch and tells the truth, which is better than no button at all.
const hasInvoice = (link: PaymentLink): boolean => !!link.reservation_id;

const isInvoiceArchived = (link: PaymentLink): boolean => !!link.invoice_path;

const downloadingInvoiceId = ref<number | null>(null);
const downloadingAllInvoices = ref(false);
const retryingInvoices = ref<Record<number, boolean>>({});
const retryInvoiceCounts = ref<Record<number, number>>({});

const INVOICE_RETRY_GAP_MS = 2000;
const INVOICE_RETRY_MAX = 300;

const downloadAllInvoices = async () => {
    downloadingAllInvoices.value = true;
    try {
        const params: Record<string, string | number> = {};
        if (fromDate.value) params.from_date = fromDate.value;
        if (toDate.value) params.to_date = toDate.value;
        if (searchPhone.value.trim()) params.phone = searchPhone.value.trim();
        if (bypassIpId.value === 'all') {
            params.bypass_all = 1;
        } else if (bypassIpId.value !== null) {
            params.bypass_ip_id = bypassIpId.value;
        }

        const res = await axios.get('/api/payment-links/invoices-zip', {
            params,
            responseType: 'blob',
        });
        const url = URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = `invoices-${fromDate.value || 'all'}.zip`;
        a.click();
        URL.revokeObjectURL(url);
    } catch (e: any) {
        const text = await e?.response?.data?.text?.();
        const msg = (() => { try { return JSON.parse(text ?? '').message; } catch { return null; } })();
        alert(msg ?? 'Failed to download invoices. Archived copies are served from storage; the rest need a live IVAC session.');
    } finally {
        downloadingAllInvoices.value = false;
    }
};

const tryDownloadInvoice = async (link: PaymentLink, forceBypassAll = false): Promise<boolean> => {
    if (!link.reservation_id) return false;
    try {
        const params: Record<string, string | number> = { txrId: link.reservation_id };
        if (forceBypassAll || bypassIpId.value === 'all') {
            params.bypass_all = 1;
        } else if (bypassIpId.value !== null) {
            params.bypass_ip_id = bypassIpId.value;
        }
        const res = await axios.get('/api/payment-links/invoice', { params, responseType: 'blob' });
        const url = URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = `invoice-${link.reservation_id}.pdf`;
        a.click();
        URL.revokeObjectURL(url);
        return true;
    } catch {
        return false;
    }
};

const downloadInvoice = async (link: PaymentLink) => {
    if (!link.reservation_id) return;
    downloadingInvoiceId.value = link.id;
    try {
        const ok = await tryDownloadInvoice(link);
        if (!ok) {
            alert('Failed to download invoice. It is not archived yet, and IVAC refused a live fetch.');
        }
    } finally {
        downloadingInvoiceId.value = null;
    }
};

const retryInvoiceUntilDownload = async (link: PaymentLink) => {
    if (retryingInvoices.value[link.id]) {
        retryingInvoices.value[link.id] = false;
        return;
    }
    if (!link.reservation_id) return;
    retryingInvoices.value[link.id] = true;
    retryInvoiceCounts.value[link.id] = 0;
    try {
        while (retryingInvoices.value[link.id] && retryInvoiceCounts.value[link.id] < INVOICE_RETRY_MAX) {
            retryInvoiceCounts.value[link.id]++;
            const ok = await tryDownloadInvoice(link, true);
            if (ok) break;
            if (!retryingInvoices.value[link.id]) break;
            await new Promise((r) => setTimeout(r, INVOICE_RETRY_GAP_MS));
        }
    } finally {
        retryingInvoices.value[link.id] = false;
    }
};

interface RecentCallback {
    id: number;
    account_phone: string | null;
    reservation_id: string | null;
    callback_url: string | null;
    callback_status: string | null;
    callback_status_code: number | null;
    callback_submitted_at: string | null;
    callback_completed_at: string | null;
}

const recentCallbacks = ref<RecentCallback[]>([]);
let recentCallbacksInterval: ReturnType<typeof setInterval> | null = null;

const fetchRecentCallbacks = async () => {
    try {
        const res = await axios.get('/api/payment-links/recent-callbacks');
        recentCallbacks.value = res.data;
    } catch (e) {
        console.error('Failed to fetch recent redirect URLs', e);
    }
};

const submitCallback = async () => {
    const url = callbackUrlInput.value.trim();
    if (!url) {
        alert('Paste the redirect URL first.');
        return;
    }
    submittingCallback.value = true;
    try {
        const res = await axios.post('/api/payment-links/callback-url', { url });
        callbackDialogOpen.value = false;
        await fetchPaymentLinks(paginationMeta.value.current_page);
        await fetchRecentCallbacks();
        alert(`Redirect URL routed to ${res.data.account_phone ?? 'reservation'} (${res.data.reservation_id}).`);
    } catch (e: any) {
        const msg = e?.response?.data?.message ?? 'Failed to submit redirect URL';
        alert(msg);
    } finally {
        submittingCallback.value = false;
    }
};

onMounted(async () => {
    tickInterval = setInterval(() => {
        nowTick.value = Date.now();
    }, 1000);
    await fetchAccounts();
    fetchPaymentLinks(parseInt(getInitialParam('page', '1'), 10) || 1);
    fetchVisaTypes();
    fetchRecentCallbacks();
    recentCallbacksInterval = setInterval(fetchRecentCallbacks, 5000);
    if (autoRefresh.value) {
        startAutoRefresh();
    }
    try {
        const res = await axios.get('/api/api-tester/context');
        bypassIps.value = res.data.bypass_ips ?? [];
        const saved = localStorage.getItem('paymentLinks.bypassIpId');
        if (saved === 'all') {
            bypassIpId.value = 'all';
        } else if (saved && bypassIps.value.some((b) => b.id === Number(saved))) {
            bypassIpId.value = Number(saved);
        } else {
            bypassIpId.value = 'all';
        }
    } catch {
        // bypass IPs unavailable — direct download fallback
    }
});

onUnmounted(() => {
    if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
    }
    if (tickInterval) {
        clearInterval(tickInterval);
        tickInterval = null;
    }
    if (recentCallbacksInterval) {
        clearInterval(recentCallbacksInterval);
        recentCallbacksInterval = null;
    }
    clearNewRowState();
});

const page = usePage();
const canDelete = computed(() => page.props.auth.permissions?.['bot.manage'] ?? false);
const isSuperAdmin = computed(() => (page.props.auth?.user as { role?: string } | undefined)?.role === 'super_admin');
// Both owner tiers review payment links; only an admin edits the visa details.
const isManager = computed(() => ['manager', 'sub_manager'].includes((page.props.auth?.user as { role?: string } | undefined)?.role ?? ''));
const canEditVisa = computed(() => isSuperAdmin.value);
const canEditReviewStatus = computed(() => isSuperAdmin.value || isManager.value);

watch(bypassIpId, (id) => {
    if (id !== null && id !== undefined) {
        localStorage.setItem('paymentLinks.bypassIpId', String(id));
    } else {
        localStorage.removeItem('paymentLinks.bypassIpId');
    }
});

const breadcrumbs = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Payment Links', href: '/payment-links' },
];

const visaStats = computed(() => {
    const byType: Record<string, { links: number; applicants: number }> = {};
    let totalLinks = 0;
    let totalApplicants = 0;

    for (const link of paymentLinks.value) {
        totalLinks++;
        const vt = link.visa_type ?? null;
        const key = vt ?? '—';
        if (!byType[key]) { byType[key] = { links: 0, applicants: 0 }; }
        byType[key].links++;
        if (link.number_of_applicants) {
            byType[key].applicants += link.number_of_applicants;
            totalApplicants += link.number_of_applicants;
        }
    }

    const sorted = Object.entries(byType).sort((a, b) => b[1].applicants - a[1].applicants);
    return { sorted, totalLinks, totalApplicants };
});

const dateSortDir = ref<'asc' | 'desc'>('desc');

const toggleDateSort = () => {
    dateSortDir.value = dateSortDir.value === 'asc' ? 'desc' : 'asc';
};

const sortedPaymentLinks = computed(() => {
    const dir = dateSortDir.value === 'asc' ? 1 : -1;
    return [...paymentLinks.value].sort((a, b) => {
        const cmp = a.latest_at.localeCompare(b.latest_at);
        return cmp * dir;
    });
});

// Agent-click filter + Group By (Agent / Visa Type)
type GroupBy = 'none' | 'agent' | 'visa_type';
const initialGroupBy = getInitialParam('group_by');
const groupBy = ref<GroupBy>(initialGroupBy === 'agent' || initialGroupBy === 'visa_type' ? initialGroupBy : 'none');
const agentFilter = ref<string | null>(getInitialParam('agent') || null);

const toggleAgentFilter = (name: string) => {
    if (!name) return;
    agentFilter.value = agentFilter.value === name ? null : name;
};

watch(groupBy, () => updateUrlState());
watch(agentFilter, () => updateUrlState());

const filteredPaymentLinks = computed(() => {
    if (!agentFilter.value) return sortedPaymentLinks.value;
    return sortedPaymentLinks.value.filter((link) => getAccountUserName(link) === agentFilter.value);
});

interface PaymentLinkGroup {
    key: string;
    label: string;
    items: { link: GroupedPaymentLink; idx: number }[];
}

const groupedPaymentLinks = computed<PaymentLinkGroup[]>(() => {
    const withIndex = filteredPaymentLinks.value.map((link, idx) => ({ link, idx }));
    if (groupBy.value === 'none') {
        return [{ key: '', label: '', items: withIndex }];
    }
    const groups = new Map<string, { link: GroupedPaymentLink; idx: number }[]>();
    for (const entry of withIndex) {
        const key = groupBy.value === 'agent'
            ? (getAccountUserName(entry.link) || 'Unassigned')
            : (entry.link.visa_type || 'Unknown');
        if (!groups.has(key)) { groups.set(key, []); }
        groups.get(key)!.push(entry);
    }
    return Array.from(groups.entries())
        .sort((a, b) => a[0].localeCompare(b[0]))
        .map(([key, items]) => ({ key, label: key, items }));
});

// Live reverse countdowns: the link must be clicked within 5 minutes of arrival,
// and the JWT backing it has its own shelf life — both tick every second.
const LINK_EXPIRY_MS = 5 * 60 * 1000;
const nowTick = ref(Date.now());
let tickInterval: ReturnType<typeof setInterval> | null = null;

function linkCountdownSecs(link: GroupedPaymentLink): number | null {
    const arrivedAt = (link.all_links?.[0] ?? link).created_at;
    if (!arrivedAt) return null;
    return Math.floor((new Date(arrivedAt).getTime() + LINK_EXPIRY_MS - nowTick.value) / 1000);
}

function jwtCountdownSecs(link: GroupedPaymentLink): number | null {
    if (!link.jwt_expires_at) return null;
    return Math.floor((new Date(link.jwt_expires_at).getTime() - nowTick.value) / 1000);
}

function formatCountdown(secs: number | null): string {
    if (secs == null) return '—';
    if (secs <= 0) return 'Expired';
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

function countdownColor(secs: number | null): string {
    if (secs == null) return 'text-zinc-400 dark:text-zinc-600';
    if (secs <= 0) return 'text-red-600 dark:text-red-400';
    if (secs <= 60) return 'text-amber-600 dark:text-amber-400';
    return 'text-emerald-600 dark:text-emerald-400';
}
</script>

<template>
    <Head title="Payment Links" />
    <AppLayout :breadcrumbs="breadcrumbs" :full-width="true">
        <div class="flex h-full flex-1 flex-col gap-3 p-3 sm:p-4">
            <!-- Page title -->
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-400 to-indigo-600 shadow-sm shadow-blue-500/30">
                    <Link class="h-4 w-4 text-white" />
                </div>
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white">Payment Links</h2>
                    <p class="text-muted-foreground text-xs">Stored payment gateway URLs from IVAC payment initiation.</p>
                </div>
            </div>

            <div class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm overflow-hidden">

                <!-- Merged filter + action header -->
                <div class="flex flex-wrap items-end gap-2 px-3 py-2.5 border-b border-zinc-200/60 dark:border-zinc-700/60">
                    <!-- Date from -->
                    <div class="flex flex-col gap-0.5 min-w-[7.5rem]">
                        <label class="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">From</label>
                        <input v-model="fromDate" type="date"
                            class="h-7 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-2 text-[11px] text-zinc-900 dark:text-zinc-100" />
                    </div>
                    <!-- Date to -->
                    <div class="flex flex-col gap-0.5 min-w-[7.5rem]">
                        <label class="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">To</label>
                        <input v-model="toDate" type="date"
                            class="h-7 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-2 text-[11px] text-zinc-900 dark:text-zinc-100" />
                    </div>
                    <!-- Phone search -->
                    <div class="flex flex-col gap-0.5 min-w-[8.75rem] flex-1">
                        <label class="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">Phone</label>
                        <input v-model="searchPhone" type="text" placeholder="01712345678"
                            class="h-7 w-full rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-2 text-[11px]"
                            @input="onSearchInput" />
                    </div>
                    <!-- Visa type -->
                    <div class="flex flex-col gap-0.5 min-w-[7.5rem]">
                        <label class="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">Visa type</label>
                        <select v-model="visaTypeFilter"
                            class="h-7 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-2 text-[11px] text-zinc-900 dark:text-zinc-100">
                            <option value="">All</option>
                            <option value="MEDICAL">Medical</option>
                            <option value="TOURIST">Tourist</option>
                            <option value="STUDENT">Student</option>
                            <option value="BUSINESS">Business</option>
                            <option value="UNKNOWN">Unknown</option>
                        </select>
                    </div>
                    <!-- Review status -->
                    <div class="flex flex-col gap-0.5 min-w-[7.5rem]">
                        <label class="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">Payment status</label>
                        <select v-model="reviewStatusFilter"
                            class="h-7 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-2 text-[11px] text-zinc-900 dark:text-zinc-100">
                            <option value="">All</option>
                            <option value="unread">Unread</option>
                            <option value="declined">Declined</option>
                            <option value="succeeded">Paid</option>
                        </select>
                    </div>
                    <!-- Callback status -->
                    <div class="flex flex-col gap-0.5 min-w-[7.5rem]">
                        <label class="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">Callback</label>
                        <select v-model="callbackStatusFilter"
                            class="h-7 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-2 text-[11px] text-zinc-900 dark:text-zinc-100">
                            <option value="">All</option>
                            <option value="success">Success</option>
                            <option value="failed">Failed</option>
                            <option value="pending">Pending</option>
                            <option value="none">None yet</option>
                        </select>
                    </div>
                    <!-- Clicked -->
                    <div class="flex flex-col gap-0.5 min-w-[6.875rem]">
                        <label class="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">Clicked</label>
                        <select v-model="clickedFilter"
                            class="h-7 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-2 text-[11px] text-zinc-900 dark:text-zinc-100">
                            <option value="">All</option>
                            <option value="yes">Clicked</option>
                            <option value="no">Not clicked</option>
                        </select>
                    </div>
                    <!-- Group by -->
                    <div class="flex flex-col gap-0.5 min-w-[8.125rem]">
                        <label class="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest">Group by</label>
                        <select v-model="groupBy"
                            class="h-7 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-2 text-[11px] text-zinc-900 dark:text-zinc-100">
                            <option value="none">None</option>
                            <option value="agent">Agent</option>
                            <option value="visa_type">Visa type</option>
                        </select>
                    </div>
                    <!-- Active agent filter chip -->
                    <div v-if="agentFilter" class="flex items-end gap-1.5 shrink-0 pb-0.5">
                        <span class="flex items-center gap-1.5 h-7 rounded-full bg-blue-100 dark:bg-blue-900/30 px-2.5 text-[11px] font-semibold text-blue-700 dark:text-blue-300">
                            Agent: {{ agentFilter }}
                            <button type="button" class="text-blue-500 hover:text-blue-700 dark:hover:text-blue-200" title="Clear agent filter" @click="agentFilter = null">
                                <X class="h-3 w-3" />
                            </button>
                        </span>
                    </div>
                    <!-- Bypass IP -->
                    <div class="flex flex-col gap-0.5 min-w-[11.25rem] flex-1">
                        <label class="text-[10px] font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-widest flex items-center gap-1">
                            <ShieldCheck class="h-3 w-3 text-blue-500" /> Bypass IP
                        </label>
                        <select v-model="bypassIpId"
                            class="h-7 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-2 text-[11px] text-zinc-900 dark:text-zinc-100">
                            <option value="all">— All (try each) —</option>
                            <option v-for="b in bypassIps" :key="b.id" :value="b.id">
                                {{ b.label }} — {{ b.ip }}{{ b.is_default ? ' ✓' : '' }}
                            </option>
                            <option :value="null">— none (direct) —</option>
                        </select>
                    </div>
                    <!-- Filter buttons -->
                    <div class="flex items-end gap-1.5 shrink-0 pb-0.5">
                        <Button size="sm" @click="applyFilters" class="h-7 text-[11px] bg-emerald-600 hover:bg-emerald-700 text-white px-3">Apply</Button>
                        <Button variant="outline" size="sm" @click="resetFilters" class="h-7 text-[11px] px-3">Reset</Button>
                        <Button size="sm" @click="toggleAutoRefresh"
                            class="h-7 text-[11px] flex items-center gap-1 px-2"
                            :class="autoRefresh ? 'bg-amber-600 text-white hover:bg-amber-700' : 'border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-800'">
                            <RotateCw class="h-3 w-3" :class="{ 'animate-spin': autoRefresh }" />
                            {{ autoRefresh ? 'Auto' : 'Auto' }}
                        </Button>
                    </div>
                    <!-- Spacer -->
                    <div class="hidden sm:block flex-grow"></div>
                    <!-- Action buttons -->
                    <div class="flex items-end gap-1.5 shrink-0 pb-0.5">
                        <Button size="sm" @click="downloadAllInvoices" :disabled="downloadingAllInvoices"
                            class="h-7 text-[11px] bg-emerald-600 hover:bg-emerald-700 text-white flex items-center gap-1 disabled:opacity-50 px-3"
                            title="Download all invoices for the current filter as a ZIP">
                            <Loader2 v-if="downloadingAllInvoices" class="h-3 w-3 animate-spin" />
                            <Download v-else class="h-3 w-3" />
                            {{ downloadingAllInvoices ? 'Downloading…' : 'All Invoices' }}
                        </Button>
                        <Button size="sm" @click="openCallbackDialog"
                            class="h-7 text-[11px] bg-blue-600 hover:bg-blue-700 text-white flex items-center gap-1 px-3">
                            <Send class="h-3 w-3" /> Redirect URL
                        </Button>
                    </div>
                </div>

                <!-- Visa stats strip -->
                <div v-if="!loading && visaStats.sorted.length > 0" class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-3 py-2 border-b border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/40 dark:bg-zinc-900/20">
                    <div
                        v-for="([type, s]) in visaStats.sorted"
                        :key="type"
                        class="flex items-center gap-2"
                    >
                        <span class="text-[10px] font-semibold text-zinc-700 dark:text-zinc-300">{{ type }}</span>
                        <span class="rounded bg-blue-500/10 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 text-[10px] font-bold tabular-nums">{{ s.links }}×</span>
                        <span class="text-[10px] text-zinc-500 dark:text-zinc-400 tabular-nums">{{ s.applicants }} BGD</span>
                        <div class="w-16 h-1 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                            <div class="h-full rounded-full bg-blue-400 dark:bg-blue-500"
                                :style="{ width: visaStats.totalApplicants ? (s.applicants / visaStats.totalApplicants * 100) + '%' : '0%' }">
                            </div>
                        </div>
                    </div>
                    <span class="ml-auto text-[10px] font-semibold text-zinc-600 dark:text-zinc-400 tabular-nums shrink-0">
                        Total {{ visaStats.totalApplicants }} BGD
                    </span>
                </div>

                <!-- Mobile cards (< sm) -->
                <div class="sm:hidden divide-y divide-zinc-200/60 dark:divide-zinc-700/60">
                    <!-- Loading skeleton -->
                    <div v-if="loading" v-for="i in 3" :key="i" class="px-3 py-3 space-y-2">
                        <div class="h-4 w-2/3 animate-pulse rounded bg-muted"></div>
                        <div class="h-3 w-full animate-pulse rounded bg-muted"></div>
                        <div class="h-3 w-1/2 animate-pulse rounded bg-muted"></div>
                    </div>
                    <!-- Empty -->
                    <div v-else-if="filteredPaymentLinks.length === 0" class="py-12 text-center text-sm text-muted-foreground">
                        {{ agentFilter ? 'No payment links for this agent.' : 'No payment links yet.' }}
                    </div>
                    <!-- Cards -->
                    <div v-else v-for="(link, index) in filteredPaymentLinks" :key="link.id" class="px-3 py-2.5 transition-colors"
                        :class="[rowBgClasses(link), isNewRow(link) ? 'row-slide-in' : '']">
                        <!-- Row 1: phone · tag  /  clicked icon · links badge -->
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex flex-col gap-0.5 min-w-0">
                                <span class="font-semibold text-[10px] text-zinc-800 dark:text-zinc-100 truncate">{{ getAccountInfo(link) }}</span>
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span v-if="isAccountCompleted(link)"
                                        class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200"
                                        title="The account behind this link is marked completed">
                                        COMPLETED
                                    </span>
                                    <span v-if="getAccountTag(link)" class="bg-zinc-100 text-black rounded px-1.5 py-0.5 text-[10px] font-semibold">{{ getAccountTag(link) }}</span>
                                    <span v-if="getAccountCenter(link)" class="bg-blue-50 text-black rounded px-1.5 py-0.5 text-[10px] font-semibold">{{ getAccountCenter(link) }}</span>
                                    <button v-if="getAccountUserName(link)" type="button"
                                        class="text-[10px] text-blue-600 dark:text-blue-400 underline underline-offset-2"
                                        @click="toggleAgentFilter(getAccountUserName(link))"
                                    >{{ getAccountUserName(link) }}</button>
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <button
                                    v-if="link.gateway_page_url"
                                    type="button"
                                    class="flex shrink-0 items-center justify-center rounded bg-red-100 p-1 dark:bg-red-900/20"
                                    title="Open latest payment link"
                                    @click="openLink(link.all_links?.[0] ?? link, (link.all_links?.[0] ?? link).gateway_page_url!)"
                                >
                                    <img src="/images/dgepay-logo.png" alt="DG ePay" class="h-4 w-4 rounded-sm" />
                                </button>
                                <span v-else class="flex shrink-0 items-center justify-center rounded bg-red-100 p-1 dark:bg-red-900/20">
                                    <img src="/images/dgepay-logo.png" alt="DG ePay" class="h-4 w-4 rounded-sm" />
                                </span>
                                <a
                                    :href="automationLogUrl(link)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="flex shrink-0 items-center gap-1 rounded bg-emerald-100 px-1.5 py-1 text-[10px] font-bold uppercase tracking-wide text-emerald-800 transition-colors hover:bg-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:hover:bg-emerald-900/50"
                                    title="Auto payment step log (opens in a new tab)"
                                >
                                    <ScrollText class="h-4 w-4" />
                                    Auto log
                                </a>
                                <span
                                    v-if="link.links_count > 1"
                                    class="bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 rounded px-1.5 py-0.5 text-[10px] font-bold cursor-pointer hover:bg-blue-200 dark:hover:bg-blue-900/50 transition-colors"
                                    @click="openModal(link)"
                                >{{ link.links_count }}×</span>
                            </div>
                        </div>
                        <!-- Row 1b: visa meta -->
                        <div v-if="link.application_id || link.visa_type || link.number_of_applicants || link.total_amount || canEditVisa" class="mt-1 flex flex-wrap items-center gap-1.5">
                            <span v-if="link.application_id" class="rounded bg-violet-500/10 text-violet-700 dark:text-violet-300 px-1.5 py-0.5 text-[10px] font-mono font-semibold">{{ link.application_id }}</span>
                            <span v-if="link.visa_type" class="rounded bg-blue-500/10 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 text-[10px]">{{ link.visa_type }}</span>
                            <span v-if="link.number_of_applicants" class="rounded bg-zinc-100 text-black px-1.5 py-0.5 text-[10px] font-semibold">{{ link.number_of_applicants }} BGD</span>
                            <span v-if="link.total_amount" class="rounded bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 px-1.5 py-0.5 text-[10px] font-semibold">৳{{ link.total_amount }}</span>
                            <button v-if="canEditVisa" type="button" class="rounded p-0.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" title="Edit visa type / applicants" @click="openVisaEdit(link)">
                                <Pencil class="h-3 w-3" />
                            </button>
                        </div>
                        <!-- Row 2: date (left) · created time (right) -->
                        <div class="mt-1 flex items-center justify-between gap-2">
                            <span class="font-semibold text-[10px] text-zinc-600 dark:text-zinc-400">{{ formatGroupDate(link.group_date) }}</span>
                            <span class="text-[10px] text-zinc-400 tabular-nums shrink-0">{{ formatDate(link.created_at) }}</span>
                        </div>
                        <!-- Row 2b: live countdowns — 5-min link expiry · JWT shelf life -->
                        <div class="mt-0.5 flex items-center gap-3">
                            <span class="text-[10px] font-mono tabular-nums" :class="countdownColor(linkCountdownSecs(link))">
                                Link {{ formatCountdown(linkCountdownSecs(link)) }}
                            </span>
                            <span class="text-[10px] font-mono tabular-nums" :class="countdownColor(jwtCountdownSecs(link))">
                                JWT {{ formatCountdown(jwtCountdownSecs(link)) }}
                            </span>
                        </div>
                        <!-- Row 3: checkout URL(s) · time · copy button — latest on top, one line per link that day -->
                        <div v-if="link.all_links?.some(ml => ml.gateway_page_url)" class="mt-1.5 flex flex-col gap-1">
                            <template v-for="(ml, mi) in link.all_links" :key="ml.id">
                                <div v-if="ml.gateway_page_url" class="flex items-center gap-1.5 flex-wrap">
                                    <a
                                        :href="ml.gateway_page_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 rounded bg-blue-500/10 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-400 hover:bg-blue-500/20 transition-colors"
                                        @click.prevent="openLink(ml, ml.gateway_page_url)"
                                    >
                                        Checkout URL <ExternalLink class="h-2.5 w-2.5" />
                                    </a>
                                    <span class="text-[9px] text-zinc-400 tabular-nums shrink-0">{{ formatDate(ml.created_at) }}</span>
                                    <span v-if="mi === 0 && link.all_links.length > 1" class="bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300 rounded px-1 py-px text-[8px] font-bold uppercase tracking-wide shrink-0">Latest</span>
                                    <Button variant="ghost" size="icon" class="h-5 w-5 shrink-0" title="Copy" @click="copyLink(ml.gateway_page_url, ml)">
                                        <Copy class="h-2.5 w-2.5" />
                                    </Button>
                                </div>
                            </template>
                        </div>
                        <div v-else class="mt-1.5 text-[9px] text-zinc-400 italic">No gateway URL</div>
                        <!-- Row 4: status (left) · review badge · actions (right) -->
                        <div class="mt-1.5 flex items-center justify-between gap-2">
                            <div class="flex flex-col gap-0.5 min-w-0">
                                <span class="text-[10px] text-zinc-400">
                                    {{ link.status_code != null ? link.status_code : '—' }}<template v-if="link.message"> · {{ link.message }}</template>
                                </span>
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <Loader2 v-if="updatingReviewStatus[link.id]" class="h-3 w-3 animate-spin text-zinc-400" />
                                <select
                                    v-else-if="canEditReviewStatus"
                                    :value="link.review_status"
                                    class="h-6 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-1.5 text-[10px] text-zinc-900 dark:text-zinc-100 cursor-pointer"
                                    @change="(e) => setReviewStatus(link, (e.target as HTMLSelectElement).value as ReviewStatus)"
                                >
                                    <option value="unread">Unread</option>
                                    <option value="declined">Declined</option>
                                    <option value="succeeded">Paid</option>
                                </select>
                                <span v-else class="h-6 rounded border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 px-1.5 text-[10px] leading-6 text-zinc-500 dark:text-zinc-400">{{ reviewStatusLabel(link.review_status) }}</span>
                            </div>
                            <div class="flex items-center gap-0.5 shrink-0">
                                <span
                                    v-if="link.callback_status"
                                    class="text-[9px] font-bold uppercase tracking-wide"
                                    :class="callbackStatusColor(link)"
                                    :title="link.callback_response || link.callback_status"
                                >{{ link.callback_status }}{{ link.callback_status_code ? ' ' + link.callback_status_code : '' }}</span>
                                <button v-if="hasInvoice(link)" @click="downloadInvoice(link)"
                                    class="disabled:opacity-50"
                                    :class="isInvoiceArchived(link) ? 'text-emerald-600 dark:text-emerald-400' : 'text-blue-600 dark:text-blue-400'"
                                    :title="isInvoiceArchived(link) ? 'Download invoice (archived)' : 'Download invoice (fetches from IVAC)'"
                                    :disabled="downloadingInvoiceId === link.id">
                                    <Loader2 v-if="downloadingInvoiceId === link.id" class="h-3.5 w-3.5 animate-spin" />
                                    <FileText v-else class="h-3.5 w-3.5" />
                                </button>
                                <button v-if="hasInvoice(link)" @click="retryInvoiceUntilDownload(link)"
                                    class="disabled:opacity-50"
                                    :class="!!retryingInvoices[link.id] ? 'text-red-500 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'"
                                    :title="!!retryingInvoices[link.id] ? 'Stop retrying' : 'Retry until download'"
                                    :disabled="downloadingInvoiceId === link.id && !retryingInvoices[link.id]">
                                    <Loader2 v-if="!!retryingInvoices[link.id]" class="h-3.5 w-3.5 animate-spin" />
                                    <RotateCw v-else class="h-3.5 w-3.5" />
                                </button>
                                <Button
                                    v-if="canDelete"
                                    variant="ghost"
                                    size="icon"
                                    class="h-6 w-6 text-destructive hover:text-destructive"
                                    title="Delete"
                                    @click="deleteLink(link)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Desktop table (>= sm) -->
                <div class="hidden sm:block overflow-x-auto">
                    <Table class="border-b table-fixed min-w-[82.5rem]">
                        <TableHeader class="bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm border-b border-zinc-200/60 dark:border-zinc-700/60">
                            <TableRow>
                                <TableHead class="pl-3 pr-2 py-1 text-center font-semibold text-zinc-900 dark:text-zinc-100 text-[10px] uppercase tracking-widest border-r w-[2.5rem]">S/N</TableHead>
                                <TableHead class="px-2 py-1 text-left font-semibold text-zinc-900 dark:text-zinc-100 text-[10px] uppercase tracking-widest border-r w-[7.5rem] shrink-0 select-none cursor-pointer hover:text-zinc-600 dark:hover:text-zinc-200" @click="toggleDateSort">
                                    <span class="inline-flex items-center gap-1.5">
                                        <CreditCard class="h-3.5 w-3.5" title="Payment link" />
                                        Date
                                        <ArrowUp v-if="dateSortDir === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else class="h-3 w-3" />
                                    </span>
                                </TableHead>
                                <TableHead class="px-2 py-1 text-left font-semibold text-zinc-900 dark:text-zinc-100 text-[10px] uppercase tracking-widest border-r w-[8.75rem]">Account</TableHead>
                                <TableHead class="px-2 py-1 text-left font-semibold text-zinc-900 dark:text-zinc-100 text-[10px] uppercase tracking-widest border-r w-[6.25rem]">Agent</TableHead>
                                <TableHead class="px-2 py-1 text-center font-semibold text-zinc-900 dark:text-zinc-100 text-[10px] uppercase tracking-widest border-r w-[6.25rem]">Center</TableHead>
                                <TableHead class="px-2 py-1 text-center font-semibold text-zinc-900 dark:text-zinc-100 text-[10px] uppercase tracking-widest border-r w-[6.875rem]">Visa Type</TableHead>
                                <TableHead class="px-2 py-1 text-center font-semibold text-zinc-900 dark:text-zinc-100 text-[10px] uppercase tracking-widest border-r w-[6.25rem]">Applicants</TableHead>
                                <TableHead class="px-2 py-1 text-center font-semibold text-zinc-900 dark:text-zinc-100 text-[10px] uppercase tracking-widest w-[10rem]">Checkout URL</TableHead>
                                <TableHead class="px-2 py-1 text-left font-semibold text-zinc-900 dark:text-zinc-100 text-[10px] uppercase tracking-widest border-l w-[6.25rem]">Time Left</TableHead>
                                <TableHead class="px-2 py-1 text-center font-semibold text-zinc-900 dark:text-zinc-100 text-[10px] uppercase tracking-widest border-l w-[6.875rem]">Payment Status</TableHead>
                                <TableHead class="px-2 py-1 text-center font-semibold text-zinc-900 dark:text-zinc-100 text-[10px] uppercase tracking-widest border-l w-[5rem]">Initiated</TableHead>
                                <TableHead class="px-2 py-1 text-center font-semibold text-zinc-900 dark:text-zinc-100 text-[10px] uppercase tracking-widest border-l w-[6.25rem]">Auto Log</TableHead>
                                <TableHead class="pl-2 pr-3 py-1 text-center font-semibold text-zinc-900 dark:text-zinc-100 text-[10px] uppercase tracking-widest border-l w-[3.75rem]">Act.</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <template v-if="loading">
                                <TableRow v-for="i in 3" :key="i">
                                    <TableCell v-for="j in 13" :key="j" :class="{ 'border-r': [1,2,3,4,5,6,7].includes(j), 'border-l': [9,10,11,12,13].includes(j) }">
                                        <div class="h-4 w-full animate-pulse rounded bg-muted"></div>
                                    </TableCell>
                                </TableRow>
                            </template>
                            <template v-else-if="filteredPaymentLinks.length === 0">
                                <TableRow class="h-20 text-center">
                                    <TableCell colspan="13" class="text-muted-foreground">
                                        {{ agentFilter ? 'No payment links for this agent.' : 'No payment links yet.' }}
                                    </TableCell>
                                </TableRow>
                            </template>
                            <template v-else v-for="group in groupedPaymentLinks" :key="group.key || 'all'">
                                <TableRow v-if="groupBy !== 'none'" class="bg-zinc-100/70 dark:bg-zinc-800/40 hover:bg-zinc-100/70 dark:hover:bg-zinc-800/40">
                                    <TableCell colspan="13" class="px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-zinc-600 dark:text-zinc-300 border-b border-zinc-200 dark:border-zinc-700">
                                        {{ group.label }}
                                        <span class="ml-1.5 text-zinc-400 font-normal normal-case">{{ group.items.length }} link{{ group.items.length === 1 ? '' : 's' }}</span>
                                    </TableCell>
                                </TableRow>
                                <TableRow v-for="{ link, idx } in group.items" :key="link.id"
                                    class="transition-colors" :class="[rowBgClasses(link), isNewRow(link) ? 'row-slide-in' : '']">
                                <!-- S/N -->
                                <TableCell class="pl-3 pr-2 py-1 text-center text-[10px] font-bold text-zinc-900 dark:text-zinc-100 font-mono tabular-nums border-r">
                                    {{ (paginationMeta.current_page - 1) * 100 + idx + 1 }}
                                </TableCell>
                                <!-- Payment icon + date + links count -->
                                <TableCell class="px-2 py-1 border-r">
                                    <div class="flex items-center gap-1.5">
                                        <button
                                            v-if="link.gateway_page_url"
                                            type="button"
                                            class="flex shrink-0 items-center justify-center rounded bg-red-100 p-1 transition-colors hover:bg-red-200 dark:bg-red-900/20 dark:hover:bg-red-900/40"
                                            title="Open latest payment link"
                                            @click="openLink(link.all_links?.[0] ?? link, (link.all_links?.[0] ?? link).gateway_page_url!)"
                                        >
                                            <img src="/images/dgepay-logo.png" alt="DG ePay" class="h-3.5 w-3.5 rounded-sm" />
                                        </button>
                                        <span v-else class="flex shrink-0 items-center justify-center rounded bg-red-100 p-1 dark:bg-red-900/20">
                                            <img src="/images/dgepay-logo.png" alt="DG ePay" class="h-3.5 w-3.5 rounded-sm" />
                                        </span>
                                        <span class="text-[10px] font-semibold text-zinc-900 dark:text-zinc-100">{{ formatGroupDate(link.group_date) }}</span>
                                        <span
                                            v-if="link.links_count > 1"
                                            class="flex h-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-blue-100 px-1.5 text-[10px] font-bold text-blue-700 cursor-pointer hover:bg-blue-200 transition-colors dark:bg-blue-900/30 dark:text-blue-300"
                                            @click="openModal(link)"
                                        >{{ link.links_count }}×</span>
                                    </div>
                                </TableCell>
                                <!-- Account: phone + tag -->
                                <TableCell class="px-2 py-1 border-r">
                                    <div class="flex flex-col gap-1 min-w-0">
                                        <span class="text-[10px] font-semibold truncate">{{ getAccountInfo(link) }}</span>
                                        <span v-if="isAccountCompleted(link)"
                                            class="w-fit rounded bg-emerald-100 px-1.5 py-0.5 text-[9px] font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200"
                                            title="The account behind this link is marked completed">
                                            COMPLETED
                                        </span>
                                        <span v-if="getAccountTag(link)" class="w-fit max-w-full truncate bg-zinc-100 text-black rounded px-1.5 py-0.5 text-[10px] font-semibold">{{ getAccountTag(link) }}</span>
                                    </div>
                                </TableCell>
                                <!-- Agent -->
                                <TableCell class="px-2 py-1 border-r">
                                    <button
                                        v-if="getAccountUserName(link)"
                                        type="button"
                                        class="text-[10px] text-blue-600 dark:text-blue-400 underline underline-offset-2 truncate hover:text-blue-800 dark:hover:text-blue-300"
                                        :title="agentFilter === getAccountUserName(link) ? 'Clear agent filter' : `Show only ${getAccountUserName(link)}`"
                                        @click="toggleAgentFilter(getAccountUserName(link))"
                                    >{{ getAccountUserName(link) }}</button>
                                    <span v-else class="text-[10px] text-zinc-900 dark:text-zinc-100">—</span>
                                </TableCell>
                                <!-- Center -->
                                <TableCell class="px-2 py-1 border-r text-center">
                                    <span v-if="getAccountCenter(link)" class="text-[10px] font-bold text-zinc-900 dark:text-zinc-100 truncate">{{ getAccountCenter(link) }}</span>
                                    <span v-else class="text-[10px] text-zinc-900 dark:text-zinc-100">—</span>
                                </TableCell>
                                <!-- Visa type -->
                                <TableCell class="px-2 py-1 border-r text-center">
                                    <div class="flex flex-col items-center gap-1 min-w-0">
                                        <span v-if="link.visa_type" class="text-[10px] font-semibold text-blue-600 dark:text-blue-400">{{ link.visa_type }}</span>
                                        <span v-else class="text-[10px] text-zinc-900 dark:text-zinc-100">—</span>
                                        <span v-if="link.application_id" class="text-[10px] font-mono text-zinc-900 dark:text-zinc-100 truncate">{{ link.application_id }}</span>
                                    </div>
                                </TableCell>
                                <!-- Applicants -->
                                <TableCell class="px-2 py-1 border-r text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <div class="flex flex-col items-center gap-1 min-w-0">
                                            <span v-if="link.number_of_applicants" class="rounded bg-zinc-100 text-black px-1.5 py-0.5 text-[10px] font-semibold shrink-0">{{ link.number_of_applicants }} BGD</span>
                                            <span v-else class="text-[10px] text-zinc-900 dark:text-zinc-100">—</span>
                                            <span v-if="link.total_amount" class="text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 shrink-0">৳{{ link.total_amount }}</span>
                                        </div>
                                        <button v-if="canEditVisa" type="button" class="shrink-0 rounded p-0.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" title="Edit visa type / applicants" @click="openVisaEdit(link)">
                                            <Pencil class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </TableCell>
                                <!-- Checkout URL(s): one per link that day, latest first — latest blue, rest muted but clickable -->
                                <TableCell class="px-2 py-1 text-center">
                                    <div v-if="link.all_links?.some(ml => ml.gateway_page_url)" class="flex flex-col items-center gap-1 min-w-0">
                                        <template v-for="(ml, mi) in link.all_links" :key="ml.id">
                                            <div v-if="ml.gateway_page_url" class="flex items-center justify-center gap-1 min-w-0">
                                                <a
                                                    :href="ml.gateway_page_url"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide truncate min-w-0 transition-colors"
                                                    :class="mi === 0
                                                        ? 'bg-blue-500/10 text-blue-600 dark:text-blue-400 hover:bg-blue-500/20'
                                                        : 'bg-zinc-500/10 text-zinc-500 dark:text-zinc-500 hover:bg-zinc-500/20'"
                                                    @click.prevent="openLink(ml, ml.gateway_page_url)"
                                                >
                                                    Checkout URL <ExternalLink class="h-2.5 w-2.5 shrink-0" />
                                                </a>
                                                <span class="text-[10px] text-zinc-900 dark:text-zinc-100 tabular-nums shrink-0">{{ new Date(ml.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', timeZone: DHAKA_TZ }) }}</span>
                                                <Button variant="ghost" size="icon" class="h-6 w-6 shrink-0" title="Copy" @click="copyLink(ml.gateway_page_url, ml)">
                                                    <Copy class="h-3 w-3" />
                                                </Button>
                                            </div>
                                        </template>
                                    </div>
                                    <span v-else class="text-[10px] text-zinc-900 dark:text-zinc-100">—</span>
                                </TableCell>
                                <!-- Time left: 5-min link countdown + JWT shelf countdown, live ticking -->
                                <TableCell class="px-2 py-1 border-l">
                                    <div class="flex flex-col gap-1">
                                        <span class="text-[10px] font-mono tabular-nums" :class="countdownColor(linkCountdownSecs(link))">
                                            Link {{ formatCountdown(linkCountdownSecs(link)) }}
                                        </span>
                                        <span class="text-[10px] font-mono tabular-nums" :class="countdownColor(jwtCountdownSecs(link))">
                                            JWT {{ formatCountdown(jwtCountdownSecs(link)) }}
                                        </span>
                                    </div>
                                </TableCell>
                                <!-- Payment status (review status) -->
                                <TableCell class="px-2 py-1 text-center border-l">
                                    <Loader2 v-if="updatingReviewStatus[link.id]" class="h-3.5 w-3.5 animate-spin mx-auto text-zinc-400" />
                                    <select
                                        v-else-if="canEditReviewStatus"
                                        :value="link.review_status"
                                        class="h-6 w-full rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-1.5 text-[10px] text-zinc-900 dark:text-zinc-100 cursor-pointer"
                                        @change="(e) => setReviewStatus(link, (e.target as HTMLSelectElement).value as ReviewStatus)"
                                    >
                                        <option value="unread">Unread</option>
                                        <option value="declined">Declined</option>
                                        <option value="succeeded">Paid</option>
                                    </select>
                                    <span v-else class="inline-block h-6 w-full rounded border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 px-1.5 text-center text-[10px] font-semibold leading-6 text-zinc-900 dark:text-zinc-100">{{ reviewStatusLabel(link.review_status) }}</span>
                                </TableCell>
                                <!-- Initiated (payment link creation time(s) — all links that day, latest first) -->
                                <TableCell class="px-2 py-1 text-center border-l">
                                    <div class="flex flex-col items-center gap-1">
                                        <span
                                            v-for="ml in (link.all_links?.length ? link.all_links : [link])"
                                            :key="ml.id"
                                            class="text-[10px] text-zinc-900 dark:text-zinc-100 tabular-nums"
                                        >{{ new Date(ml.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: DHAKA_TZ }) }}</span>
                                    </div>
                                </TableCell>
                                <!-- Auto Log -->
                                <TableCell class="px-2 py-1 text-center border-l">
                                    <a
                                        :href="automationLogUrl(link)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 rounded bg-emerald-500/10 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400 hover:bg-emerald-500/20 transition-colors"
                                        title="Auto payment step log (opens in a new tab)"
                                    >
                                        <ScrollText class="h-3 w-3" />
                                        Auto Log
                                    </a>
                                </TableCell>
                                <!-- Actions -->
                                <TableCell class="pl-2 pr-3 py-1 text-center border-l">
                                    <div class="flex flex-col items-center gap-1">
                                        <div class="flex items-center justify-center gap-1">
                                            <Button
                                                v-if="canDelete"
                                                variant="ghost"
                                                size="icon"
                                                class="h-6 w-6 text-destructive hover:text-destructive"
                                                title="Delete"
                                                @click="deleteLink(link)"
                                            >
                                                <Trash2 class="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                        <button v-if="hasInvoice(link)" @click="downloadInvoice(link)"
                                            class="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide hover:underline disabled:opacity-50"
                                            :class="isInvoiceArchived(link) ? 'text-emerald-600 dark:text-emerald-400' : 'text-blue-600 dark:text-blue-400'"
                                            :title="isInvoiceArchived(link) ? 'Download invoice (archived)' : 'Download invoice (fetches from IVAC)'"
                                            :disabled="downloadingInvoiceId === link.id">
                                            <Loader2 v-if="downloadingInvoiceId === link.id" class="h-3.5 w-3.5 animate-spin" />
                                            <FileText v-else class="h-3.5 w-3.5" /> Invoice
                                        </button>
                                        <button v-if="hasInvoice(link)" @click="retryInvoiceUntilDownload(link)"
                                            class="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide hover:underline disabled:opacity-50"
                                            :class="!!retryingInvoices[link.id] ? 'text-red-500 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'"
                                            :title="!!retryingInvoices[link.id] ? 'Stop retrying' : 'Retry until download'"
                                            :disabled="downloadingInvoiceId === link.id && !retryingInvoices[link.id]">
                                            <Loader2 v-if="!!retryingInvoices[link.id]" class="h-3.5 w-3.5 animate-spin" />
                                            <RotateCw v-else class="h-3.5 w-3.5" />
                                            {{ !!retryingInvoices[link.id] ? retryInvoiceCounts[link.id] ?? 0 : 'Retry' }}
                                        </button>
                                    </div>
                                </TableCell>
                                </TableRow>
                            </template>
                        </TableBody>
                    </Table>
                </div>
                <DataTablePagination :meta="paginationMeta" @page-change="fetchPaymentLinks" />
            </div>

            <!-- Public REST API Documentation -->
            <div v-if="false" class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40 p-3 sm:p-4">
                <h3 class="text-sm font-bold tracking-tight mb-1">Public REST API</h3>
                <p class="text-xs text-muted-foreground mb-3">
                    Fetch payment links by date range. No authentication required.
                </p>

                <div class="flex flex-col gap-3 text-xs">
                    <div>
                        <div class="font-semibold uppercase tracking-widest text-[10px] text-zinc-500 dark:text-zinc-400 mb-1">Endpoint</div>
                        <code class="block rounded bg-zinc-100 dark:bg-zinc-950 text-emerald-700 dark:text-emerald-300 px-3 py-1.5 font-mono overflow-x-auto">POST https://ipms.senda.fit/api/payment-links/list</code>
                    </div>

                    <div>
                        <div class="font-semibold uppercase tracking-widest text-[10px] text-zinc-500 dark:text-zinc-400 mb-1">Headers</div>
                        <code class="block rounded bg-zinc-100 dark:bg-zinc-950 text-zinc-700 dark:text-zinc-200 px-3 py-1.5 font-mono overflow-x-auto">Content-Type: application/json
Accept: application/json</code>
                    </div>

                    <div>
                        <div class="font-semibold uppercase tracking-widest text-[10px] text-zinc-500 dark:text-zinc-400 mb-1">Request Body</div>
                        <code class="block rounded bg-zinc-100 dark:bg-zinc-950 text-zinc-700 dark:text-zinc-200 px-3 py-1.5 font-mono whitespace-pre">{
  "from_date": "2026-05-01",
  "to_date": "2026-05-05"
}</code>
                        <p class="text-[10px] text-zinc-500 mt-1">Both fields are optional. Format: <code class="font-mono">YYYY-MM-DD</code>. Omit either to leave that bound open.</p>
                    </div>

                    <div>
                        <div class="font-semibold uppercase tracking-widest text-[10px] text-zinc-500 dark:text-zinc-400 mb-1">Response (200)</div>
                        <pre class="rounded bg-zinc-100 dark:bg-zinc-950 text-zinc-700 dark:text-zinc-200 px-3 py-2.5 font-mono text-xs leading-relaxed overflow-x-auto"><span class="text-blue-600 dark:text-blue-400">{</span>
  <span class="text-purple-600 dark:text-purple-300">"count"</span><span class="text-zinc-500">:</span> <span class="text-orange-600 dark:text-orange-300">2</span><span class="text-zinc-500">,</span>
  <span class="text-purple-600 dark:text-purple-300">"from_date"</span><span class="text-zinc-500">:</span> <span class="text-emerald-600 dark:text-emerald-300">"2026-05-01"</span><span class="text-zinc-500">,</span>
  <span class="text-purple-600 dark:text-purple-300">"to_date"</span><span class="text-zinc-500">:</span> <span class="text-emerald-600 dark:text-emerald-300">"2026-05-05"</span><span class="text-zinc-500">,</span>
  <span class="text-purple-600 dark:text-purple-300">"data"</span><span class="text-zinc-500">:</span> <span class="text-blue-600 dark:text-blue-400">[</span>
    <span class="text-blue-600 dark:text-blue-400">{</span>
      <span class="text-purple-600 dark:text-purple-300">"id"</span><span class="text-zinc-500">:</span> <span class="text-orange-600 dark:text-orange-300">123</span><span class="text-zinc-500">,</span>
      <span class="text-purple-600 dark:text-purple-300">"account_phone"</span><span class="text-zinc-500">:</span> <span class="text-emerald-600 dark:text-emerald-300">"01712345678"</span><span class="text-zinc-500">,</span>
      <span class="text-purple-600 dark:text-purple-300">"gateway_page_url"</span><span class="text-zinc-500">:</span> <span class="text-emerald-600 dark:text-emerald-300">"https://..."</span><span class="text-zinc-500">,</span>
      <span class="text-purple-600 dark:text-purple-300">"status_code"</span><span class="text-zinc-500">:</span> <span class="text-orange-600 dark:text-orange-300">200</span><span class="text-zinc-500">,</span>
      <span class="text-purple-600 dark:text-purple-300">"message"</span><span class="text-zinc-500">:</span> <span class="text-emerald-600 dark:text-emerald-300">"OK"</span><span class="text-zinc-500">,</span>
      <span class="text-purple-600 dark:text-purple-300">"success_flag"</span><span class="text-zinc-500">:</span> <span class="text-blue-600 dark:text-blue-300">true</span><span class="text-zinc-500">,</span>
      <span class="text-purple-600 dark:text-purple-300">"created_at"</span><span class="text-zinc-500">:</span> <span class="text-emerald-600 dark:text-emerald-300">"2026-05-05T07:30:01.000000Z"</span>
    <span class="text-blue-600 dark:text-blue-400">}</span>
  <span class="text-blue-600 dark:text-blue-400">]</span>
<span class="text-blue-600 dark:text-blue-400">}</span></pre>
                    </div>
                </div>
            </div>

            <!-- Recent Redirect URLs (fed by the public ingest API, polled every 5s) -->
            <div class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40 p-3 sm:p-4">
                <div class="flex items-center justify-between mb-1">
                    <h3 class="text-sm font-bold tracking-tight">Recent Redirect URLs</h3>
                    <span class="text-[10px] text-muted-foreground">Auto-refreshes every 5s</span>
                </div>
                <p class="text-xs text-muted-foreground mb-3">
                    Post a redirect URL from anywhere — no login required — and it appears here once matched to a reservation.
                </p>

                <code class="block rounded bg-zinc-100 dark:bg-zinc-950 text-emerald-700 dark:text-emerald-300 px-3 py-1.5 font-mono text-[11px] overflow-x-auto mb-1">POST https://ipms.senda.fit/api/payment-links/redirect-url  &#123; "url": "&lt;redirect url&gt;" &#125;</code>
                <code class="block rounded bg-zinc-100 dark:bg-zinc-950 text-emerald-700 dark:text-emerald-300 px-3 py-1.5 font-mono text-[11px] overflow-x-auto mb-3">GET  https://ipms.senda.fit/api/payment-links/redirect-url?url=&lt;url-encoded redirect url&gt;</code>

                <div v-if="recentCallbacks.length === 0" class="text-xs text-muted-foreground py-4 text-center">
                    No redirect URLs submitted yet.
                </div>
                <div v-else class="flex flex-col gap-1.5 max-h-80 overflow-y-auto">
                    <div
                        v-for="cb in recentCallbacks"
                        :key="cb.id"
                        class="flex items-center gap-2 rounded border border-zinc-200/60 dark:border-zinc-700/60 bg-white dark:bg-zinc-950 px-2.5 py-1.5"
                    >
                        <span class="text-[10px] font-semibold text-zinc-700 dark:text-zinc-300 tabular-nums shrink-0 w-28">{{ cb.account_phone ?? '—' }}</span>
                        <span
                            v-if="cb.callback_status"
                            class="text-[9px] font-bold uppercase tracking-wide shrink-0"
                            :class="callbackStatusColor(cb as unknown as PaymentLink)"
                        >{{ cb.callback_status }}{{ cb.callback_status_code ? ' ' + cb.callback_status_code : '' }}</span>
                        <span class="flex-1 min-w-0 truncate text-[11px] font-mono text-zinc-500 dark:text-zinc-400" :title="cb.callback_url ?? ''">{{ cb.callback_url }}</span>
                        <span class="text-[10px] text-zinc-400 dark:text-zinc-500 tabular-nums shrink-0">{{ cb.callback_submitted_at ? formatDate(cb.callback_submitted_at) : '' }}</span>
                        <Button variant="ghost" size="icon" class="h-6 w-6 shrink-0" title="Copy" @click="copyLink(cb.callback_url ?? '')">
                            <Copy class="h-3 w-3" />
                        </Button>
                    </div>
                </div>
            </div>
        <!-- All Links Modal -->
        <Dialog v-model:open="modalOpen">
            <DialogContent class="w-full max-w-2xl p-0 flex flex-col">
                <DialogHeader class="p-3 border-b border-zinc-200 dark:border-zinc-800">
                    <DialogTitle class="text-[14px] font-semibold">All Payment Links — {{ modalPhone }}</DialogTitle>
                </DialogHeader>
                <div class="flex flex-col gap-2 max-h-[60vh] overflow-y-auto p-3">
                    <div
                        v-for="(ml, i) in modalLinks"
                        :key="ml.id"
                        class="rounded border border-zinc-200/60 dark:border-zinc-700/60 p-2 flex flex-col gap-1.5"
                        :class="ml.is_clicked ? 'border-green-500/40 bg-green-500/5' : 'bg-zinc-50/40 dark:bg-zinc-900/20'"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-1.5">
                                <span class="text-[10px] font-semibold text-zinc-700 dark:text-zinc-300 uppercase tracking-widest">{{ formatDate(ml.created_at) }}</span>
                                <span v-if="i === 0" class="bg-emerald-100 text-black rounded px-1 py-px text-[9px] font-semibold uppercase tracking-wide">Latest</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="flex items-center gap-1">
                                    <CheckCircle2 v-if="ml.is_clicked" class="h-3.5 w-3.5 text-green-500" />
                                    <Circle v-else class="h-3.5 w-3.5 text-zinc-400" />
                                    <span class="text-[10px] text-zinc-600 dark:text-zinc-400">{{ ml.is_clicked ? 'Clicked' : 'Not clicked' }}</span>
                                </div>
                                <a :href="automationLogUrl(ml)" target="_blank" rel="noopener noreferrer"
                                    class="flex items-center gap-0.5 text-[10px] font-semibold text-emerald-700 hover:underline dark:text-emerald-400"
                                    title="Auto payment log (opens in a new tab)">
                                    <ScrollText class="h-3 w-3" /> Auto log
                                </a>
                                <span v-if="ml.callback_status" class="text-[9px] font-bold uppercase tracking-wide"
                                    :class="callbackStatusColor(ml)"
                                    :title="ml.callback_response || ''">
                                    cb: {{ ml.callback_status }}{{ ml.callback_status_code ? ' ' + ml.callback_status_code : '' }}
                                </span>
                                <button v-if="hasInvoice(ml)" @click="downloadInvoice(ml)"
                                    class="flex items-center gap-0.5 text-[10px] font-semibold hover:underline disabled:opacity-50"
                                    :class="isInvoiceArchived(ml) ? 'text-emerald-600 dark:text-emerald-400' : 'text-blue-600 dark:text-blue-400'"
                                    :title="isInvoiceArchived(ml) ? 'Download invoice (archived)' : 'Download invoice (fetches from IVAC)'"
                                    :disabled="downloadingInvoiceId === ml.id">
                                    <Loader2 v-if="downloadingInvoiceId === ml.id" class="h-3 w-3 animate-spin" />
                                    <FileText v-else class="h-3 w-3" /> Invoice
                                </button>
                                <button v-if="hasInvoice(ml)" @click="retryInvoiceUntilDownload(ml)"
                                    class="flex items-center gap-0.5 text-[10px] font-semibold hover:underline disabled:opacity-50"
                                    :class="!!retryingInvoices[ml.id] ? 'text-red-500 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'"
                                    :title="!!retryingInvoices[ml.id] ? 'Stop retrying' : 'Retry until download'"
                                    :disabled="downloadingInvoiceId === ml.id && !retryingInvoices[ml.id]">
                                    <Loader2 v-if="!!retryingInvoices[ml.id]" class="h-3 w-3 animate-spin" />
                                    <RotateCw v-else class="h-3 w-3" />
                                    {{ !!retryingInvoices[ml.id] ? (retryInvoiceCounts[ml.id] ?? 0) : 'Retry' }}
                                </button>
                            </div>
                        </div>
                        <div v-if="ml.gateway_page_url" class="flex items-center gap-2">
                            <a
                                :href="ml.gateway_page_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-[10px] font-mono text-blue-600 dark:text-blue-400 hover:underline break-all flex-1"
                                @click.prevent="openLink(ml, ml.gateway_page_url)"
                            >{{ ml.gateway_page_url }}</a>
                            <Button variant="ghost" size="icon" class="h-5 w-5 shrink-0" title="Copy" @click="copyLink(ml.gateway_page_url, ml)">
                                <Copy class="h-2.5 w-2.5" />
                            </Button>
                        </div>
                        <div v-if="ml.redirect_gateway_url" class="flex items-center gap-2">
                            <a
                                :href="ml.redirect_gateway_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-[10px] font-mono text-blue-600 dark:text-blue-400 hover:underline break-all flex-1"
                                @click.prevent="openLink(ml, ml.redirect_gateway_url)"
                            >{{ ml.redirect_gateway_url }}</a>
                            <Button variant="ghost" size="icon" class="h-5 w-5 shrink-0" title="Copy" @click="copyLink(ml.redirect_gateway_url, ml)">
                                <Copy class="h-2.5 w-2.5" />
                            </Button>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Post redirect URL dialog -->
        <Dialog v-model:open="callbackDialogOpen">
            <DialogContent class="w-full max-w-2xl p-0 gap-0 overflow-hidden flex flex-col">
                <DialogHeader class="flex flex-row items-center gap-3 space-y-0 px-5 py-4 border-b border-zinc-200/70 dark:border-zinc-800">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-400 to-indigo-600 shadow-sm shadow-blue-500/30">
                        <Send class="h-5 w-5 text-white" />
                    </div>
                    <div class="text-left">
                        <DialogTitle class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">Post Redirect URL</DialogTitle>
                        <p class="text-xs text-muted-foreground mt-0.5">Hand the post-payment callback to the bot that owns this reservation.</p>
                    </div>
                </DialogHeader>

                <div class="flex flex-col gap-4 p-5">
                    <div class="flex gap-2.5 rounded-lg border border-blue-200/70 dark:border-blue-900/50 bg-blue-50/70 dark:bg-blue-950/30 px-3.5 py-3">
                        <Link class="h-4 w-4 shrink-0 mt-0.5 text-blue-500 dark:text-blue-400" />
                        <p class="text-[13px] leading-relaxed text-blue-900/90 dark:text-blue-200/90">
                            After completing the payment in your browser, paste the full redirect URL below.
                            It's matched to the right payment link by its <span class="font-mono font-semibold">tran_id</span>,
                            then the bot holding that reservation fires it across all bypass IPs.
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Redirect URL</label>
                        <textarea
                            v-model="callbackUrlInput"
                            rows="7"
                            spellcheck="false"
                            placeholder="https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=…&data=…"
                            class="w-full resize-y rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 p-3 text-[13px] font-mono leading-relaxed text-zinc-800 dark:text-zinc-100 placeholder:text-zinc-400 dark:placeholder:text-zinc-600 focus:outline-none focus:ring-2 focus:ring-blue-500/60 focus:border-blue-500"
                        ></textarea>
                        <span class="text-[11px] text-muted-foreground">Must contain a <span class="font-mono">tran_id</span> query parameter.</span>
                    </div>
                </div>

                <div class="flex justify-end gap-2 px-5 py-4 border-t border-zinc-200/70 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-900/40">
                    <Button variant="outline" @click="callbackDialogOpen = false">Cancel</Button>
                    <Button
                        :disabled="submittingCallback || !callbackUrlInput.trim()"
                        class="bg-blue-600 hover:bg-blue-700 text-white"
                        @click="submitCallback"
                    >
                        <Loader2 v-if="submittingCallback" class="mr-1.5 h-4 w-4 animate-spin" />
                        <Send v-else class="mr-1.5 h-4 w-4" />
                        Submit Redirect URL
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="visaEditDialogOpen">
            <DialogContent class="w-full max-w-md p-0 gap-0 overflow-hidden flex flex-col">
                <DialogHeader class="flex flex-row items-center gap-3 space-y-0 px-5 py-4 border-b border-zinc-200/70 dark:border-zinc-800">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-400 to-violet-500 shadow-sm shadow-blue-500/30">
                        <Pencil class="h-5 w-5 text-white" />
                    </div>
                    <div class="text-left">
                        <DialogTitle class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">Edit Visa Info</DialogTitle>
                        <p class="text-xs text-muted-foreground mt-0.5">{{ visaEditTarget?.account_phone }} · updates the account's booking record</p>
                    </div>
                </DialogHeader>

                <div class="flex flex-col gap-4 p-5">
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Visa Type</label>
                        <select
                            v-model="visaEditForm.visa_type"
                            class="h-9 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-3 text-[12px] text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-blue-500/60 focus:border-blue-500"
                        >
                            <option v-for="vt in visaTypesList" :key="vt" :value="vt">{{ vt }}</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="text-[11px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Number of Applicants</label>
                        <input
                            v-model.number="visaEditForm.number_of_applicants"
                            type="number"
                            min="0"
                            class="h-9 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-3 text-[12px] text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-blue-500/60 focus:border-blue-500"
                        />
                    </div>
                    <p v-if="visaEditError" class="text-[11px] text-red-500">{{ visaEditError }}</p>
                </div>

                <div class="flex justify-end gap-2 px-5 py-4 border-t border-zinc-200/70 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-900/40">
                    <Button variant="outline" :disabled="visaEditSaving" @click="visaEditDialogOpen = false">Cancel</Button>
                    <Button
                        class="bg-blue-500 hover:bg-blue-600 text-white"
                        :disabled="visaEditSaving"
                        @click="saveVisaEdit"
                    >
                        {{ visaEditSaving ? 'Saving…' : 'Save' }}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
        </div>
    </AppLayout>
</template>

<style scoped>
/* New rows found by a background auto-refresh slide in from the left with a brief highlight. */
@keyframes payment-link-row-slide-in {
    0% {
        opacity: 0;
        transform: translateX(-18px);
        background-color: rgb(16 185 129 / 0.18);
    }
    60% {
        opacity: 1;
        transform: translateX(0);
        background-color: rgb(16 185 129 / 0.14);
    }
    100% {
        opacity: 1;
        transform: translateX(0);
        background-color: transparent;
    }
}

.row-slide-in {
    animation: payment-link-row-slide-in 1.6s ease-out;
}

@media (prefers-reduced-motion: reduce) {
    .row-slide-in {
        animation: none;
    }
}
</style>
