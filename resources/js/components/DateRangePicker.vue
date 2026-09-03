<script setup lang="ts">
import { CalendarRange, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { cn } from '@/lib/utils';

interface DateRangeValue {
    from: string;
    to: string;
}

const props = withDefaults(
    defineProps<{
        modelValue: DateRangeValue;
        placeholder?: string;
        triggerClass?: string;
        disabled?: boolean;
    }>(),
    {
        placeholder: 'Select dates',
        triggerClass: '',
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: DateRangeValue];
}>();

// IVAC's home market (Bangladesh) weekend is Friday + Saturday, not Sat/Sun —
// those two weekdays are disabled here and stripped from any expanded range.
const isWeekend = (dateStr: string): boolean => {
    const day = new Date(dateStr + 'T00:00:00Z').getUTCDay();
    return day === 5 || day === 6;
};

const pad = (n: number) => String(n).padStart(2, '0');
const toDateStr = (y: number, m: number, d: number) => `${y}-${pad(m + 1)}-${pad(d)}`;

const open = ref(false);
const pendingFrom = ref<string | null>(null);
const hoverDate = ref<string | null>(null);

const viewYear = ref(0);
const viewMonth = ref(0);

const resetViewToSelection = () => {
    const base = props.modelValue.from ? new Date(props.modelValue.from + 'T00:00:00Z') : new Date();
    viewYear.value = base.getUTCFullYear();
    viewMonth.value = base.getUTCMonth();
};
resetViewToSelection();

watch(open, (isOpen) => {
    if (isOpen) {
        pendingFrom.value = null;
        hoverDate.value = null;
        resetViewToSelection();
    }
});

const goPrevMonth = () => {
    if (viewMonth.value === 0) {
        viewMonth.value = 11;
        viewYear.value -= 1;
    } else {
        viewMonth.value -= 1;
    }
};
const goNextMonth = () => {
    if (viewMonth.value === 11) {
        viewMonth.value = 0;
        viewYear.value += 1;
    } else {
        viewMonth.value += 1;
    }
};

const monthLabel = computed(() =>
    new Date(Date.UTC(viewYear.value, viewMonth.value, 1)).toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }),
);

const weekdayLabels = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

interface DayCell {
    date: string;
    day: number;
}

const gridCells = computed<(DayCell | null)[]>(() => {
    const y = viewYear.value;
    const m = viewMonth.value;
    const firstDow = new Date(Date.UTC(y, m, 1)).getUTCDay();
    const daysInMonth = new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
    const cells: (DayCell | null)[] = [];
    for (let i = 0; i < firstDow; i++) {
        cells.push(null);
    }
    for (let d = 1; d <= daysInMonth; d++) {
        cells.push({ date: toDateStr(y, m, d), day: d });
    }
    return cells;
});

const rangeFrom = computed(() => props.modelValue.from || null);
const rangeTo = computed(() => props.modelValue.to || null);

const previewTo = computed(() => (pendingFrom.value && hoverDate.value ? hoverDate.value : null));

const isInSelectedRange = (date: string): boolean => {
    if (!rangeFrom.value || !rangeTo.value) {
        return false;
    }
    return date > rangeFrom.value && date < rangeTo.value;
};

const isInPreviewRange = (date: string): boolean => {
    if (!pendingFrom.value || !previewTo.value) {
        return false;
    }
    const [a, b] = pendingFrom.value <= previewTo.value ? [pendingFrom.value, previewTo.value] : [previewTo.value, pendingFrom.value];
    return date > a && date < b;
};

const cellState = (date: string) => {
    const disabled = isWeekend(date);
    const isStart = date === (pendingFrom.value ?? rangeFrom.value);
    const isEnd = (!pendingFrom.value && date === rangeTo.value) || (pendingFrom.value !== null && date === previewTo.value);
    return {
        disabled,
        isStart,
        isEnd,
        inRange: isInSelectedRange(date) || isInPreviewRange(date),
    };
};

const selectDate = (date: string) => {
    if (isWeekend(date)) {
        return;
    }
    if (!pendingFrom.value) {
        pendingFrom.value = date;
        emit('update:modelValue', { from: date, to: '' });
        return;
    }
    const from = pendingFrom.value <= date ? pendingFrom.value : date;
    const to = pendingFrom.value <= date ? date : pendingFrom.value;
    pendingFrom.value = null;
    hoverDate.value = null;
    emit('update:modelValue', { from, to });
    open.value = false;
};

const clearRange = () => {
    pendingFrom.value = null;
    hoverDate.value = null;
    emit('update:modelValue', { from: '', to: '' });
};

const fmtShort = (dateStr: string) =>
    new Date(dateStr + 'T00:00:00Z').toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });

const triggerLabel = computed(() => {
    if (!props.modelValue.from) {
        return props.placeholder;
    }
    if (!props.modelValue.to || props.modelValue.to === props.modelValue.from) {
        return fmtShort(props.modelValue.from);
    }
    return `${fmtShort(props.modelValue.from)} – ${fmtShort(props.modelValue.to)}`;
});

const selectedDayCount = computed(() => {
    if (!props.modelValue.from) {
        return 0;
    }
    const end = props.modelValue.to || props.modelValue.from;
    if (end < props.modelValue.from) {
        return 0;
    }
    let count = 0;
    let cur = props.modelValue.from;
    let guard = 0;
    while (cur <= end && guard < 366) {
        if (!isWeekend(cur)) {
            count++;
        }
        const dt = new Date(cur + 'T00:00:00Z');
        dt.setUTCDate(dt.getUTCDate() + 1);
        cur = dt.toISOString().slice(0, 10);
        guard++;
    }
    return count;
});
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger
            type="button"
            :disabled="disabled"
            :class="
                cn(
                    'inline-flex items-center gap-1.5 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 text-left tabular-nums transition-colors hover:border-emerald-400 dark:hover:border-emerald-600 focus:outline-none focus:ring-1 focus:ring-emerald-500/50 disabled:cursor-not-allowed disabled:opacity-50',
                    triggerClass,
                )
            "
        >
            <CalendarRange class="h-3 w-3 shrink-0 text-zinc-400" />
            <span :class="cn('truncate', !modelValue.from && 'text-zinc-400')">{{ triggerLabel }}</span>
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                :side-offset="4"
                align="start"
                class="z-50 w-[16.25rem] rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2 shadow-lg outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95"
            >
                <div class="flex items-center justify-between px-1 pb-1.5">
                    <button
                        type="button"
                        @click="goPrevMonth"
                        class="flex h-6 w-6 items-center justify-center rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-500"
                    >
                        <ChevronLeft class="h-3.5 w-3.5" />
                    </button>
                    <span class="text-[11px] font-semibold text-zinc-700 dark:text-zinc-200">{{ monthLabel }}</span>
                    <button
                        type="button"
                        @click="goNextMonth"
                        class="flex h-6 w-6 items-center justify-center rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 text-zinc-500"
                    >
                        <ChevronRight class="h-3.5 w-3.5" />
                    </button>
                </div>
                <div class="grid grid-cols-7 gap-y-0.5 px-1">
                    <span
                        v-for="(label, idx) in weekdayLabels"
                        :key="`wd-${idx}`"
                        :class="
                            cn(
                                'flex h-6 items-center justify-center text-[9px] font-semibold uppercase text-zinc-400',
                                (idx === 5 || idx === 6) && 'text-zinc-300 dark:text-zinc-600',
                            )
                        "
                    >{{ label }}</span>
                    <template v-for="(cell, idx) in gridCells" :key="`c-${idx}`">
                        <span v-if="!cell" class="h-7 w-7" />
                        <button
                            v-else
                            type="button"
                            :disabled="cellState(cell.date).disabled"
                            @click="selectDate(cell.date)"
                            @mouseenter="hoverDate = cell.date"
                            :class="
                                cn(
                                    'flex h-7 w-7 items-center justify-center rounded text-[10px] tabular-nums transition-colors',
                                    cellState(cell.date).disabled && 'cursor-not-allowed text-zinc-300 dark:text-zinc-700 line-through',
                                    !cellState(cell.date).disabled && !cellState(cell.date).inRange && !cellState(cell.date).isStart && !cellState(cell.date).isEnd &&
                                        'text-zinc-700 dark:text-zinc-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/30',
                                    cellState(cell.date).inRange && 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-900 dark:text-emerald-200 rounded-none',
                                    (cellState(cell.date).isStart || cellState(cell.date).isEnd) && 'bg-emerald-600 text-white hover:bg-emerald-600',
                                )
                            "
                        >{{ cell.day }}</button>
                    </template>
                </div>
                <div class="mt-1.5 flex items-center justify-between border-t border-zinc-100 dark:border-zinc-800 pt-1.5 px-1">
                    <span class="text-[9px] text-zinc-400">Fri/Sat unavailable</span>
                    <div class="flex items-center gap-2">
                        <span v-if="selectedDayCount > 0" class="text-[9px] text-zinc-400 tabular-nums">{{ selectedDayCount }}d</span>
                        <button
                            type="button"
                            @click="clearRange"
                            class="text-[9px] font-semibold text-zinc-500 hover:text-red-600 dark:hover:text-red-400"
                        >Clear</button>
                    </div>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
