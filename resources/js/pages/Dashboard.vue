<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Users,
    Shield,
    Ticket,
    Link as LinkIcon,
    Globe,
    Bot,
    ArrowUpRight,
    Activity,
    Zap,
    CheckCircle2,
    XCircle,
} from 'lucide-vue-next';
import { ref, computed, onMounted } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

interface DashboardStats {
    accounts_count: number;
    accounts_active_count: number;
    accounts_completed_count: number;
    captcha_providers_count: number;
    captcha_tokens_count: number;
    payment_links_count: number;
    payment_links_today: number;
    payment_links_success_count: number;
    payment_links_total_success_count: number;
    payment_links_declined_count: number;
    payment_links_completed_count: number;
    workers_count: number;
    workers_online_count: number;
}

interface VisaTypeRow {
    visa_type: string;
    total: number;
    declined: number;
    completed: number;
    bookings: number;
    applicants: number;
}

interface DailyTypeRow {
    date: string;
    visa_type: string;
    total: number;
    declined: number;
    completed: number;
    success: number;
    applicants: number;
}

interface DayGroup {
    sn: number;
    date: string;
    isHoliday: boolean;
    types: { visa_type: string; total: number; declined: number; completed: number; success: number; applicants: number }[];
    totalLinks: number;
    totalSuccess: number;
    totalDeclined: number;
    totalCompleted: number;
    totalApplicants: number;
}

interface WeekSection {
    label: string;
    groups: DayGroup[];
    subtotalLinks: number;
    subtotalSuccess: number;
    subtotalDeclined: number;
    subtotalCompleted: number;
    subtotalApplicants: number;
}

const props = defineProps<{
    stats: DashboardStats;
    todayByType: VisaTypeRow[];
    monthRows: DailyTypeRow[];
    allTimeByType: VisaTypeRow[];
}>();

const page = usePage();
const isSuperAdmin = computed(() => (page.props.auth?.user as { role?: string } | undefined)?.role === 'super_admin');

const botStatus = ref<{ running: boolean; pid: number | null; started_at: string | null } | null>(null);
const botLoading = ref(false);

const todayTotalBookings = computed(() => props.todayByType.reduce((s, r) => s + r.bookings, 0));
const todayTotalLinks = computed(() => props.todayByType.reduce((s, r) => s + Number(r.total), 0));
const todayTotalDeclined = computed(() => props.todayByType.reduce((s, r) => s + Number(r.declined), 0));
const todayTotalApplicants = computed(() => props.todayByType.reduce((s, r) => s + Number(r.applicants), 0));
const todayTotalCompleted = computed(() => props.todayByType.reduce((s, r) => s + Number(r.completed), 0));
const allTimeTotalApplicants = computed(() => props.allTimeByType.reduce((s, r) => s + Number(r.applicants), 0));

const paymentSuccessRate = computed(() => {
    const total = props.stats.payment_links_count;
    if (total === 0) { return null; }
    return Math.round((props.stats.payment_links_success_count / total) * 100);
});

function getDayOfWeek(dateStr: string): number {
    return new Date(dateStr + 'T00:00:00').getDay(); // 0=Sun … 6=Sat
}

function isHoliday(dateStr: string): boolean {
    const d = getDayOfWeek(dateStr);
    return d === 5 || d === 6; // Fri or Sat
}

function weekNumberInMonth(dateStr: string): number {
    const d = new Date(dateStr + 'T00:00:00');
    const firstDay = new Date(d.getFullYear(), d.getMonth(), 1).getDay();
    return Math.floor((d.getDate() - 1 + firstDay) / 7);
}

const weekSections = computed((): WeekSection[] => {
    const dateMap = new Map<string, DayGroup>();
    for (const row of props.monthRows) {
        if (!dateMap.has(row.date)) {
            dateMap.set(row.date, {
                sn: 0,
                date: row.date,
                isHoliday: isHoliday(row.date),
                types: [],
                totalLinks: 0,
                totalSuccess: 0,
                totalDeclined: 0,
                totalCompleted: 0,
                totalApplicants: 0,
            });
        }
        const g = dateMap.get(row.date)!;
        g.types.push({ visa_type: row.visa_type, total: Number(row.total), declined: Number(row.declined), completed: Number(row.completed), success: Number(row.success), applicants: Number(row.applicants) });
        g.totalLinks += Number(row.total);
        g.totalSuccess += Number(row.success);
        g.totalDeclined += Number(row.declined);
        g.totalCompleted += Number(row.completed);
        g.totalApplicants += Number(row.applicants);
    }

    // assign S/N in date order
    let sn = 1;
    const allGroups = Array.from(dateMap.values()).sort((a, b) => a.date.localeCompare(b.date));
    for (const g of allGroups) {
        g.sn = sn++;
    }

    // group into weeks
    const weekMap = new Map<number, WeekSection>();
    for (const group of allGroups) {
        const wk = weekNumberInMonth(group.date);
        if (!weekMap.has(wk)) {
            weekMap.set(wk, { label: `Week ${wk + 1}`, groups: [], subtotalLinks: 0, subtotalSuccess: 0, subtotalDeclined: 0, subtotalCompleted: 0, subtotalApplicants: 0 });
        }
        const section = weekMap.get(wk)!;
        section.groups.push(group);
        section.subtotalLinks += group.totalLinks;
        section.subtotalSuccess += group.totalSuccess;
        section.subtotalDeclined += group.totalDeclined;
        section.subtotalCompleted += group.totalCompleted;
        section.subtotalApplicants += group.totalApplicants;
    }

    return Array.from(weekMap.entries())
        .sort(([a], [b]) => a - b)
        .map(([, s]) => s);
});

const monthTotals = computed(() => ({
    links: weekSections.value.reduce((s, w) => s + w.subtotalLinks, 0),
    success: weekSections.value.reduce((s, w) => s + w.subtotalSuccess, 0),
    declined: weekSections.value.reduce((s, w) => s + w.subtotalDeclined, 0),
    completed: weekSections.value.reduce((s, w) => s + w.subtotalCompleted, 0),
    applicants: weekSections.value.reduce((s, w) => s + w.subtotalApplicants, 0),
}));

const currentMonthLabel = computed(() => {
    return new Date().toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
});

onMounted(() => {
    if (isSuperAdmin.value) {
        botLoading.value = true;
        axios
            .get('/api/bot/status')
            .then((res) => {
                botStatus.value = {
                    running: res.data.running ?? false,
                    pid: res.data.pid ?? null,
                    started_at: res.data.started_at ?? null,
                };
            })
            .catch(() => { botStatus.value = null; })
            .finally(() => { botLoading.value = false; });
    }
});

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: dashboard().url }];

function formatDate(dateStr: string): string {
    return dateStr.replace(/-/g, '/');
}

const visaTypeStyle: Record<string, string> = {
    MEDICAL:  'text-blue-600 dark:text-blue-400',
    TOURIST:  'text-emerald-600 dark:text-emerald-400',
    STUDENT:  'text-violet-600 dark:text-violet-400',
    BUSINESS: 'text-amber-600 dark:text-amber-400',
    UNKNOWN:  'text-zinc-500 dark:text-zinc-400',
};

function vtText(type: string): string {
    return visaTypeStyle[type] ?? visaTypeStyle.UNKNOWN;
}

function getDayName(dateStr: string): string {
    return new Date(dateStr + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short' });
}

function paymentLinksUrl(date: string, visaType?: string): string {
    const params = new URLSearchParams({ from_date: date, to_date: date });
    if (visaType) { params.set('visa_type', visaType); }
    return `/payment-links?${params.toString()}`;
}

const todayIso = new Date().toISOString().split('T')[0];
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full w-full flex-1 flex-col gap-6 p-4 md:p-6">

            <!-- Page header -->
            <div class="max-w-7xl">
                <h1 class="text-xl font-semibold tracking-tight">Overview</h1>
                <p class="mt-0.5 text-sm text-muted-foreground">IVAC booking performance and system status.</p>
            </div>

            <!-- Primary metric cards -->
            <div class="grid max-w-7xl grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="col-span-2 rounded-xl border border-border bg-card p-5 sm:col-span-1">
                    <p class="text-xs font-medium text-muted-foreground">Bookings today</p>
                    <p class="mt-2 text-3xl font-bold tabular-nums tracking-tight">{{ todayTotalLinks }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        <template v-if="todayTotalDeclined"><span class="font-semibold text-red-600 dark:text-red-400">{{ todayTotalDeclined }} declined</span> · </template>
                        <span class="font-semibold text-foreground">{{ todayTotalBookings }}</span> success ·
                        <template v-if="todayTotalCompleted"><span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ todayTotalCompleted }}</span> completed · </template>
                        {{ todayTotalApplicants }} applicants
                    </p>
                </div>
                <div class="col-span-2 rounded-xl border border-border bg-card p-5 sm:col-span-1">
                    <p class="text-xs font-medium text-muted-foreground">Applicants today</p>
                    <p class="mt-2 text-3xl font-bold tabular-nums tracking-tight text-emerald-600 dark:text-emerald-400">{{ todayTotalApplicants }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">persons</p>
                </div>
                <div class="rounded-xl border border-border bg-card p-5">
                    <p class="text-xs font-medium text-muted-foreground">This month</p>
                    <p class="mt-2 text-3xl font-bold tabular-nums tracking-tight">{{ monthTotals.links }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        <template v-if="monthTotals.declined"><span class="font-semibold text-red-600 dark:text-red-400">{{ monthTotals.declined }} declined</span> · </template>
                        <span class="font-semibold text-foreground">{{ monthTotals.success }}</span> success ·
                        <template v-if="monthTotals.completed"><span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ monthTotals.completed }}</span> completed · </template>
                        {{ monthTotals.applicants }} applicants
                    </p>
                </div>
                <div class="rounded-xl border border-border bg-card p-5">
                    <p class="text-xs font-medium text-muted-foreground">All-time</p>
                    <p class="mt-2 text-3xl font-bold tabular-nums tracking-tight">{{ stats.payment_links_total_success_count }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        <template v-if="stats.payment_links_declined_count"><span class="font-semibold text-red-600 dark:text-red-400">{{ stats.payment_links_declined_count }} declined</span> · </template>
                        <span class="font-semibold text-foreground">{{ stats.payment_links_success_count }}</span> success ·
                        <template v-if="stats.payment_links_completed_count"><span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ stats.payment_links_completed_count }}</span> completed · </template>
                        {{ allTimeTotalApplicants }} applicants
                    </p>
                </div>
            </div>

            <!-- Today by visa type -->
            <div class="max-w-7xl rounded-xl border border-border bg-card">
                <div class="flex items-center justify-between border-b border-border px-5 py-3.5">
                    <h2 class="text-sm font-semibold">Today — by visa type</h2>
                    <Link :href="paymentLinksUrl(todayIso)" class="font-mono text-xs text-muted-foreground transition-colors hover:text-primary">
                        {{ new Date().toLocaleDateString('en-CA').replace(/-/g, '/') }} <ArrowUpRight class="inline h-3 w-3" />
                    </Link>
                </div>
                <div v-if="todayByType.length" class="divide-y divide-border/50">
                    <div v-for="row in todayByType" :key="row.visa_type" class="flex items-center justify-between px-5 py-3.5">
                        <span class="text-sm font-semibold" :class="vtText(row.visa_type)">{{ row.visa_type }}</span>
                        <div class="flex items-center gap-10">
                            <div class="text-right">
                                <p class="text-base font-bold tabular-nums text-muted-foreground">{{ row.total }}</p>
                                <p class="text-[11px] text-muted-foreground">payment link</p>
                            </div>
                            <div v-if="row.declined" class="text-right">
                                <p class="text-base font-bold tabular-nums text-red-600 dark:text-red-400">{{ row.declined }}</p>
                                <p class="text-[11px] text-muted-foreground">declined</p>
                            </div>
                            <div v-if="row.completed" class="text-right">
                                <p class="text-base font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ row.completed }}</p>
                                <p class="text-[11px] text-muted-foreground">completed</p>
                            </div>
                            <div class="text-right">
                                <p class="text-base font-bold tabular-nums">{{ row.bookings }}</p>
                                <p class="text-[11px] text-muted-foreground">bookings</p>
                            </div>
                            <div class="text-right">
                                <p class="text-base font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ row.applicants }}</p>
                                <p class="text-[11px] text-muted-foreground">applicants</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="px-5 py-4 text-sm text-muted-foreground">No successful bookings yet today.</div>
            </div>

            <!-- All-time by visa type -->
            <div class="max-w-7xl rounded-xl border border-border bg-card">
                <div class="flex items-center justify-between border-b border-border px-5 py-3.5">
                    <h2 class="text-sm font-semibold">All-time by visa type</h2>
                    <span class="text-xs text-muted-foreground">{{ stats.payment_links_success_count }} bookings · {{ allTimeTotalApplicants }} BGD</span>
                </div>
                <div v-if="allTimeByType.length" class="overflow-x-auto">
                    <table class="w-full text-xs" style="border-right: 1px solid var(--border)">
                        <thead>
                            <tr class="bg-muted/50">
                                <th class="border-b border-r border-border py-1.5 pl-4 pr-2 text-left text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Visa type</th>
                                <th class="border-b border-r border-border px-2 py-1.5 text-right text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Payment Link</th>
                                <th class="border-b border-r border-border px-2 py-1.5 text-right text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Bookings</th>
                                <th class="border-b border-r border-border px-2 py-1.5 text-right text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Declined</th>
                                <th class="border-b border-r border-border px-2 py-1.5 text-right text-[10px] font-semibold uppercase tracking-wider text-muted-foreground" title="Bookings whose account is marked completed">Completed</th>
                                <th class="border-b border-border px-2 py-1.5 pr-4 text-right text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Applicants</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in allTimeByType" :key="row.visa_type" :class="i % 2 === 0 ? 'bg-card' : 'bg-muted/20'">
                                <td class="border-b border-r border-border/50 py-1.5 pl-4 pr-2 font-semibold" :class="vtText(row.visa_type)">{{ row.visa_type }}</td>
                                <td class="border-b border-r border-border/50 px-2 py-1.5 text-right tabular-nums text-muted-foreground">{{ row.total }}</td>
                                <td class="border-b border-r border-border/50 px-2 py-1.5 text-right tabular-nums font-semibold">{{ row.bookings }}</td>
                                <td class="border-b border-r border-border/50 px-2 py-1.5 text-right tabular-nums" :class="row.declined ? 'font-semibold text-red-600 dark:text-red-400' : 'text-muted-foreground'">{{ row.declined || '—' }}</td>
                                <td class="border-b border-r border-border/50 px-2 py-1.5 text-right tabular-nums" :class="row.completed ? 'font-semibold text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'">{{ row.completed || '—' }}</td>
                                <td class="border-b border-border/50 px-2 py-1.5 pr-4 text-right tabular-nums font-bold text-emerald-600 dark:text-emerald-400">{{ row.applicants }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-border bg-muted/50">
                                <td class="border-r border-border/60 py-1.5 pl-4 pr-2 text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Total</td>
                                <td class="border-r border-border/60 px-2 py-1.5 text-right tabular-nums font-bold">{{ stats.payment_links_total_success_count }}</td>
                                <td class="border-r border-border/60 px-2 py-1.5 text-right tabular-nums font-bold">{{ stats.payment_links_success_count }}</td>
                                <td class="border-r border-border/60 px-2 py-1.5 text-right tabular-nums font-bold" :class="stats.payment_links_declined_count ? 'text-red-600 dark:text-red-400' : ''">{{ stats.payment_links_declined_count || '—' }}</td>
                                <td class="border-r border-border/60 px-2 py-1.5 text-right tabular-nums font-bold" :class="stats.payment_links_completed_count ? 'text-emerald-600 dark:text-emerald-400' : ''">{{ stats.payment_links_completed_count || '—' }}</td>
                                <td class="px-2 py-1.5 pr-4 text-right tabular-nums font-bold text-emerald-600 dark:text-emerald-400">{{ allTimeTotalApplicants }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p v-else class="px-5 py-8 text-center text-xs text-muted-foreground">No completed bookings yet.</p>
            </div>

            <!-- System status strip -->
            <div class="grid max-w-7xl gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <Link href="/accounts" class="group flex items-center justify-between rounded-xl border border-border bg-card px-4 py-3 transition-colors hover:bg-muted/30">
                    <div class="flex items-center gap-2.5">
                        <Users class="h-4 w-4 text-muted-foreground" />
                        <div>
                            <p class="text-xs text-muted-foreground">Accounts</p>
                            <p class="text-sm font-semibold tabular-nums">{{ stats.accounts_count }} <span class="text-xs font-normal text-muted-foreground">/ {{ stats.accounts_active_count }} active</span><span v-if="stats.accounts_completed_count" class="text-xs font-normal text-emerald-600 dark:text-emerald-400"> · {{ stats.accounts_completed_count }} completed</span></p>
                        </div>
                    </div>
                    <ArrowUpRight class="h-3.5 w-3.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                </Link>
                <Link href="/captcha-providers" class="group flex items-center justify-between rounded-xl border border-border bg-card px-4 py-3 transition-colors hover:bg-muted/30">
                    <div class="flex items-center gap-2.5">
                        <Shield class="h-4 w-4 text-muted-foreground" />
                        <div>
                            <p class="text-xs text-muted-foreground">Captcha</p>
                            <p class="text-sm font-semibold tabular-nums">{{ stats.captcha_providers_count }} providers</p>
                        </div>
                    </div>
                    <ArrowUpRight class="h-3.5 w-3.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                </Link>
                <Link href="/captcha-control" class="group flex items-center justify-between rounded-xl border border-border bg-card px-4 py-3 transition-colors hover:bg-muted/30">
                    <div class="flex items-center gap-2.5">
                        <Ticket class="h-4 w-4 text-muted-foreground" />
                        <div>
                            <p class="text-xs text-muted-foreground">Token pool</p>
                            <p class="text-sm font-semibold tabular-nums">{{ stats.captcha_tokens_count }}</p>
                        </div>
                    </div>
                    <ArrowUpRight class="h-3.5 w-3.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                </Link>
                <Link href="/payment-links" class="group flex items-center justify-between rounded-xl border border-border bg-card px-4 py-3 transition-colors hover:bg-muted/30">
                    <div class="flex items-center gap-2.5">
                        <LinkIcon class="h-4 w-4 text-muted-foreground" />
                        <div>
                            <p class="text-xs text-muted-foreground">Payment links</p>
                            <p class="text-sm font-semibold tabular-nums">{{ stats.payment_links_success_count }}<span class="text-xs font-normal text-muted-foreground"> / {{ stats.payment_links_count }}<template v-if="paymentSuccessRate != null"> · {{ paymentSuccessRate }}%</template></span></p>
                        </div>
                    </div>
                    <ArrowUpRight class="h-3.5 w-3.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                </Link>
                <Link href="/bot-control" class="group flex items-center justify-between rounded-xl border border-border bg-card px-4 py-3 transition-colors hover:bg-muted/30">
                    <div class="flex items-center gap-2.5">
                        <Globe class="h-4 w-4 text-muted-foreground" />
                        <div>
                            <p class="text-xs text-muted-foreground">Workers</p>
                            <p class="text-sm font-semibold tabular-nums">{{ stats.workers_online_count }} <span class="text-xs font-normal text-muted-foreground">/ {{ stats.workers_count }} online</span></p>
                        </div>
                    </div>
                    <ArrowUpRight class="h-3.5 w-3.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                </Link>
            </div>

            <!-- Bot status (super_admin only) -->
            <div v-if="isSuperAdmin" class="max-w-7xl rounded-xl border border-border bg-card px-5 py-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                            <Bot class="h-4 w-4" />
                        </div>
                        <div>
                            <p class="text-sm font-semibold">Local bot</p>
                            <p class="text-xs text-muted-foreground">IVAC booking process on this host</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <template v-if="botLoading">
                            <Activity class="h-3.5 w-3.5 animate-pulse text-muted-foreground" />
                            <span class="text-xs text-muted-foreground">Checking…</span>
                        </template>
                        <template v-else-if="botStatus?.running">
                            <CheckCircle2 class="h-4 w-4 text-emerald-500" />
                            <span class="text-sm font-medium text-emerald-600 dark:text-emerald-400">Running</span>
                            <span v-if="botStatus.pid" class="text-xs text-muted-foreground">PID {{ botStatus.pid }}</span>
                        </template>
                        <template v-else>
                            <XCircle class="h-4 w-4 text-muted-foreground" />
                            <span class="text-sm text-muted-foreground">Stopped</span>
                        </template>
                        <Link href="/bot-control" class="inline-flex items-center gap-1 rounded-md border border-border px-2.5 py-1 text-xs font-medium transition-colors hover:bg-muted/50">
                            Bot Control <ArrowUpRight class="h-3 w-3" />
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Quick links -->
            <div class="flex max-w-7xl flex-wrap gap-2">
                <Link href="/accounts" class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                    <Users class="h-3.5 w-3.5" /> Accounts
                </Link>
                <Link href="/captcha-providers" class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                    <Shield class="h-3.5 w-3.5" /> Captcha providers
                </Link>
                <Link href="/captcha-control" class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                    <Zap class="h-3.5 w-3.5" /> Captcha control
                </Link>
                <Link href="/payment-links" class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                    <LinkIcon class="h-3.5 w-3.5" /> Payment links
                </Link>
                <Link v-if="isSuperAdmin" href="/bot-control" class="inline-flex items-center gap-1.5 rounded-lg border border-amber-500/30 bg-amber-500/5 px-3 py-1.5 text-xs font-medium text-amber-700 transition-colors hover:bg-amber-500/10 dark:text-amber-400">
                    <Bot class="h-3.5 w-3.5" /> Bot control
                </Link>
            </div>

            <!-- Current month — full width, very bottom -->
            <div class="rounded-xl border border-border bg-card pb-2">
                <div class="flex items-center justify-between border-b border-border px-5 py-3.5">
                    <h2 class="text-sm font-semibold">{{ currentMonthLabel }}</h2>
                    <div class="flex items-center gap-3 text-xs text-muted-foreground">
                        <span>
                            <span class="font-semibold text-foreground">{{ monthTotals.links }}</span> links ·
                            <span class="font-semibold text-foreground">{{ monthTotals.success }}</span> booked
                            <template v-if="monthTotals.declined"> · <span class="font-semibold text-red-600 dark:text-red-400">{{ monthTotals.declined }} declined</span></template>
                            <template v-if="monthTotals.completed"> · <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ monthTotals.completed }} completed</span></template> ·
                            <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ monthTotals.applicants }}</span> BGD
                        </span>
                        <Link href="/monthly-report" class="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 font-medium text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground">
                            Monthly report <ArrowUpRight class="h-3 w-3" />
                        </Link>
                    </div>
                </div>

                <div v-if="weekSections.length" class="flex flex-col gap-4 px-4 py-4">
                    <div v-for="week in weekSections" :key="week.label" class="overflow-hidden rounded-lg border border-border">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 border-b border-border bg-muted/40 px-3 py-2">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">{{ week.label }}</span>
                            <span class="font-mono text-[11px] font-semibold tabular-nums text-foreground">
                                {{ week.subtotalLinks }} links · {{ week.subtotalSuccess }} success
                                <template v-if="week.subtotalDeclined"> · <span class="text-red-600 dark:text-red-400">{{ week.subtotalDeclined }} declined</span></template>
                                <template v-if="week.subtotalCompleted"> · <span class="text-emerald-600 dark:text-emerald-400">{{ week.subtotalCompleted }} completed</span></template>
                                · {{ week.subtotalApplicants }} BGD
                            </span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse text-xs">
                                <thead>
                                    <tr class="border-b border-border">
                                        <th class="py-1.5 pl-3 pr-2 text-center text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">S/N</th>
                                        <th class="px-2 py-1.5 text-left text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Date</th>
                                        <th class="px-2 py-1.5 text-left text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Visa type</th>
                                        <th class="px-2 py-1.5 text-right text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Payment Link</th>
                                        <th class="px-2 py-1.5 text-right text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Success</th>
                                        <th class="px-2 py-1.5 text-right text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Declined</th>
                                        <th class="px-2 py-1.5 text-right text-[10px] font-semibold uppercase tracking-wider text-muted-foreground" title="Bookings whose account is marked completed">Completed</th>
                                        <th class="px-2 py-1.5 pr-3 text-right text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Applicants</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="(group, gi) in week.groups" :key="group.date">
                                        <tr
                                            v-for="(typeRow, ti) in group.types"
                                            :key="group.date + typeRow.visa_type"
                                            :class="[
                                                gi === week.groups.length - 1 && ti === group.types.length - 1 ? '' : 'border-b border-border/60',
                                                group.isHoliday ? 'bg-rose-50/40 dark:bg-rose-950/10' : (gi % 2 === 0 ? 'bg-card' : 'bg-muted/10'),
                                            ]"
                                        >
                                            <td v-if="ti === 0" :rowspan="group.types.length"
                                                class="py-1.5 pl-3 pr-2 text-center align-middle font-mono tabular-nums text-muted-foreground">
                                                {{ group.sn }}
                                            </td>
                                            <td v-if="ti === 0" :rowspan="group.types.length"
                                                class="px-2 py-1.5 align-middle"
                                                :class="group.isHoliday ? 'text-rose-500 dark:text-rose-400' : ''">
                                                <Link :href="paymentLinksUrl(group.date)" class="group/datelink inline-flex items-center gap-1 hover:opacity-80 transition-opacity">
                                                    <span class="font-mono font-semibold underline">{{ formatDate(group.date) }}</span>
                                                    <span class="ml-0.5 text-[10px] font-medium" :class="group.isHoliday ? 'text-rose-400' : 'text-muted-foreground'">{{ getDayName(group.date) }}</span>
                                                    <ArrowUpRight class="h-2.5 w-2.5 opacity-0 transition-opacity group-hover/datelink:opacity-60" />
                                                </Link>
                                            </td>
                                            <td class="px-2 py-1.5 font-medium" :class="vtText(typeRow.visa_type)">
                                                <Link :href="paymentLinksUrl(group.date, typeRow.visa_type)" class="transition-opacity hover:opacity-70">{{ typeRow.visa_type }}</Link>
                                            </td>
                                            <td class="px-2 py-1.5 text-right font-mono tabular-nums text-muted-foreground">{{ typeRow.total }}</td>
                                            <td class="px-2 py-1.5 text-right font-mono tabular-nums font-semibold">{{ typeRow.success }}</td>
                                            <td class="px-2 py-1.5 text-right font-mono tabular-nums" :class="typeRow.declined ? 'font-semibold text-red-600 dark:text-red-400' : 'text-muted-foreground'">{{ typeRow.declined || '—' }}</td>
                                            <td class="px-2 py-1.5 text-right font-mono tabular-nums" :class="typeRow.completed ? 'font-semibold text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'">{{ typeRow.completed || '—' }}</td>
                                            <td class="px-2 py-1.5 pr-3 text-right font-mono tabular-nums text-muted-foreground">{{ typeRow.applicants }}</td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 rounded-lg border border-border px-3 py-2.5">
                        <span class="text-xs font-bold uppercase tracking-wider">Month total</span>
                        <span class="font-mono text-sm font-bold tabular-nums">
                            {{ monthTotals.links }} links · {{ monthTotals.success }} success
                            <template v-if="monthTotals.declined"> · <span class="text-red-600 dark:text-red-400">{{ monthTotals.declined }} declined</span></template>
                            <template v-if="monthTotals.completed"> · <span class="text-emerald-600 dark:text-emerald-400">{{ monthTotals.completed }} completed</span></template>
                            · {{ monthTotals.applicants }} BGD
                        </span>
                    </div>
                </div>
                <p v-else class="px-5 py-8 text-center text-xs text-muted-foreground">No bookings this month yet.</p>
            </div>

        </div>
    </AppLayout>
</template>
