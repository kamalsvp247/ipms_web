<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowUpRight, ChevronLeft, ChevronRight, Pencil, Save, X } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

interface DailyTypeRow {
    date: string;
    original_visa_type: string;
    visa_type: string;
    total: number;
    declined: number;
    success: number;
    completed: number;
    applicants: number;
    is_overridden: boolean;
    has_declined: boolean;
}

interface TypeRow {
    original_visa_type: string;
    visa_type: string;
    total: number;
    declined: number;
    success: number;
    completed: number;
    applicants: number;
    is_overridden: boolean;
    has_declined: boolean;
}

interface DayGroup {
    sn: number;
    date: string;
    isHoliday: boolean;
    types: TypeRow[];
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
    monthRows: DailyTypeRow[];
    visaTypes: string[];
    month: string;
    prevMonth: string;
    nextMonth: string;
    isCurrentMonth: boolean;
    canEdit: boolean;
}>();

const editMode = ref(false);
const saving = ref(false);

interface Draft {
    visa_type: string;
    applicants: number;
}

const drafts = reactive<Record<string, Draft>>({});

function draftKey(date: string, originalVisaType: string): string {
    return `${date}|${originalVisaType}`;
}

function startEdit(): void {
    for (const key of Object.keys(drafts)) {
        delete drafts[key];
    }
    for (const row of props.monthRows) {
        drafts[draftKey(row.date, row.original_visa_type)] = {
            visa_type: row.visa_type,
            applicants: row.applicants,
        };
    }
    editMode.value = true;
}

function cancelEdit(): void {
    editMode.value = false;
}

function saveEdits(): void {
    const rows = props.monthRows
        .map((row) => {
            const draft = drafts[draftKey(row.date, row.original_visa_type)];
            if (!draft) { return null; }
            if (draft.visa_type === row.visa_type && draft.applicants === row.applicants) { return null; }

            return {
                date: row.date,
                original_visa_type: row.original_visa_type,
                visa_type: draft.visa_type,
                applicants: draft.applicants,
            };
        })
        .filter((r): r is NonNullable<typeof r> => r !== null);

    if (rows.length === 0) {
        editMode.value = false;
        return;
    }

    saving.value = true;
    router.post('/monthly-report/overrides', { rows }, {
        preserveScroll: true,
        onFinish: () => {
            saving.value = false;
            editMode.value = false;
        },
    });
}

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
        g.types.push({
            original_visa_type: row.original_visa_type,
            visa_type: row.visa_type,
            total: Number(row.total),
            declined: Number(row.declined),
            success: Number(row.success),
            completed: Number(row.completed),
            applicants: Number(row.applicants),
            is_overridden: row.is_overridden,
            has_declined: row.has_declined,
        });
        g.totalLinks += Number(row.total);
        g.totalSuccess += Number(row.success);
        g.totalDeclined += Number(row.declined);
        g.totalCompleted += Number(row.completed);
        g.totalApplicants += Number(row.applicants);
    }

    let sn = 1;
    const allGroups = Array.from(dateMap.values()).sort((a, b) => a.date.localeCompare(b.date));
    for (const g of allGroups) {
        g.sn = sn++;
    }

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

const monthGrandTotal = computed(() => monthTotals.value.links);

const monthLabel = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
    { title: 'Monthly Report', href: `/monthly-report?month=${new Date().toISOString().slice(0, 7)}` },
];

function formatDate(dateStr: string): string {
    return dateStr.replace(/-/g, '/');
}

const visaTypeTextClass = 'text-blue-600 dark:text-blue-400';

function getDayName(dateStr: string): string {
    return new Date(dateStr + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short' });
}

function paymentLinksUrl(date: string, visaType?: string): string {
    const params = new URLSearchParams({ from_date: date, to_date: date });
    if (visaType) { params.set('visa_type', visaType); }
    return `/payment-links?${params.toString()}`;
}
</script>

<template>
    <Head title="Monthly Report" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full w-full flex-1 flex-col gap-6 p-4 md:p-6">

            <div class="max-w-7xl">
                <h1 class="text-xl font-semibold tracking-tight">Monthly Report</h1>
                <p class="mt-0.5 text-sm text-muted-foreground">Booking performance broken down by month.</p>
            </div>

            <div class="max-w-7xl rounded-xl border border-border bg-card pb-2">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3.5">
                    <div class="flex items-center gap-2">
                        <Link
                            :href="`/monthly-report?month=${prevMonth}`"
                            class="inline-flex items-center justify-center rounded-md border border-border p-1.5 text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground"
                        >
                            <ChevronLeft class="h-4 w-4" />
                        </Link>
                        <h2 class="min-w-[9rem] text-center text-sm font-semibold">{{ monthLabel }}</h2>
                        <Link
                            :href="`/monthly-report?month=${nextMonth}`"
                            class="inline-flex items-center justify-center rounded-md border border-border p-1.5 text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground"
                        >
                            <ChevronRight class="h-4 w-4" />
                        </Link>
                        <Link
                            v-if="!isCurrentMonth"
                            :href="`/monthly-report?month=${new Date().toISOString().slice(0, 7)}`"
                            class="ml-1 rounded-md border border-border px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground"
                        >
                            Today
                        </Link>
                    </div>
                    <div class="flex items-center gap-3 text-xs text-muted-foreground">
                        <span>
                            <span class="font-semibold text-foreground">{{ monthGrandTotal }}</span> total ·
                            <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ monthTotals.success }}</span> success ·
                            <span v-if="monthTotals.declined" class="font-semibold text-red-600 dark:text-red-400">{{ monthTotals.declined }}</span><span v-if="monthTotals.declined"> declined ·</span>
                            <span v-if="monthTotals.completed" class="font-semibold text-emerald-600 dark:text-emerald-400">{{ monthTotals.completed }}</span><span v-if="monthTotals.completed"> completed ·</span>
                            <span class="font-semibold text-foreground">{{ monthTotals.applicants }}</span> BGD
                        </span>
                        <template v-if="canEdit">
                            <button
                                v-if="!editMode"
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 font-medium text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground"
                                @click="startEdit"
                            >
                                <Pencil class="h-3 w-3" /> Edit
                            </button>
                            <template v-else>
                                <button
                                    type="button"
                                    :disabled="saving"
                                    class="inline-flex items-center gap-1 rounded-md border border-emerald-500/40 bg-emerald-500/10 px-2 py-1 font-medium text-emerald-700 transition-colors hover:bg-emerald-500/20 disabled:opacity-50 dark:text-emerald-400"
                                    @click="saveEdits"
                                >
                                    <Save class="h-3 w-3" /> {{ saving ? 'Saving…' : 'Save' }}
                                </button>
                                <button
                                    type="button"
                                    :disabled="saving"
                                    class="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 font-medium text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground disabled:opacity-50"
                                    @click="cancelEdit"
                                >
                                    <X class="h-3 w-3" /> Cancel
                                </button>
                            </template>
                        </template>
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
                                            <td class="px-2 py-1.5 font-medium" :class="editMode ? '' : visaTypeTextClass">
                                                <select
                                                    v-if="editMode"
                                                    v-model="drafts[draftKey(group.date, typeRow.original_visa_type)].visa_type"
                                                    class="w-full rounded border border-border bg-background px-1.5 py-1 text-xs"
                                                    :class="visaTypeTextClass"
                                                >
                                                    <option v-for="vt in visaTypes" :key="vt" :value="vt">{{ vt }}</option>
                                                </select>
                                                <Link v-else :href="paymentLinksUrl(group.date, typeRow.visa_type)" class="inline-flex items-center gap-1 transition-opacity hover:opacity-70">
                                                    {{ typeRow.visa_type }}
                                                    <span v-if="typeRow.is_overridden" class="rounded bg-amber-500/10 px-1 text-[9px] font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">edited</span>
                                                </Link>
                                            </td>
                                            <td class="px-2 py-1.5 text-right font-mono tabular-nums text-muted-foreground">{{ typeRow.total }}</td>
                                            <td class="px-2 py-1.5 text-right font-mono tabular-nums font-semibold">{{ typeRow.success }}</td>
                                            <td class="px-2 py-1.5 text-right font-mono tabular-nums" :class="typeRow.declined ? 'font-semibold text-red-600 dark:text-red-400' : 'text-muted-foreground'">{{ typeRow.declined || '—' }}</td>
                                            <td class="px-2 py-1.5 text-right font-mono tabular-nums" :class="typeRow.completed ? 'font-semibold text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'">{{ typeRow.completed || '—' }}</td>
                                            <td class="px-2 py-1.5 pr-3 text-right font-mono tabular-nums text-muted-foreground">
                                                <input
                                                    v-if="editMode"
                                                    v-model.number="drafts[draftKey(group.date, typeRow.original_visa_type)].applicants"
                                                    type="number"
                                                    min="0"
                                                    class="w-16 rounded border border-border bg-background px-1.5 py-1 text-right text-xs tabular-nums"
                                                />
                                                <template v-else>{{ typeRow.applicants }}</template>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 rounded-lg border border-border px-3 py-2.5">
                        <span class="text-xs font-bold uppercase tracking-wider">Month total</span>
                        <span class="font-mono text-sm font-bold tabular-nums">
                            {{ monthGrandTotal }} links · {{ monthTotals.success }} success
                            <template v-if="monthTotals.declined"> · <span class="text-red-600 dark:text-red-400">{{ monthTotals.declined }} declined</span></template>
                            <template v-if="monthTotals.completed"> · <span class="text-emerald-600 dark:text-emerald-400">{{ monthTotals.completed }} completed</span></template>
                            · {{ monthTotals.applicants }} BGD
                        </span>
                    </div>
                </div>
                <p v-else class="px-5 py-8 text-center text-xs text-muted-foreground">No bookings this month.</p>
            </div>

        </div>
    </AppLayout>
</template>
