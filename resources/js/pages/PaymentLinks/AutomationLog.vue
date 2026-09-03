<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, XCircle, Loader2, Clock, AlertTriangle, ExternalLink, KeyRound } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';

interface LinkInfo {
    id: number;
    account_phone: string | null;
    reservation_id: string | null;
    gateway_page_url: string | null;
    callback_url: string | null;
    callback_status: string | null;
    callback_status_code: number | null;
    is_fake: boolean;
    created_at: string | null;
    expires_at: string | null;
    expiry_minutes: number;
    is_expired: boolean;
    is_superseded: boolean;
}

interface AccountInfo {
    id: number;
    phone: string;
    tag: string | null;
    auto_payment: boolean;
    auto_payment_method: string | null;
    auto_payment_method_label: string | null;
    auto_payment_wallet: string | null;
}

interface AttemptInfo {
    id: number;
    status: 'pending' | 'running' | 'succeeded' | 'failed';
    method: string;
    method_label: string | null;
    stage: string | null;
    attempts: number;
    max_attempts: number;
    callback_url: string | null;
    last_error: string | null;
    driver_log: string | null;
    started_at: string | null;
    finished_at: string | null;
    duration_ms: number | null;
}

interface Step {
    key: string;
    label: string;
    state: 'complete' | 'current' | 'failed' | 'pending';
}

const props = defineProps<{
    link: LinkInfo;
    account: AccountInfo | null;
    attempt: AttemptInfo | null;
    steps: Step[];
    eligibility: Record<string, boolean> | null;
}>();

const STATUS_STYLES: Record<string, string> = {
    succeeded: 'bg-emerald-100 text-black dark:bg-emerald-900/40 dark:text-emerald-100',
    failed: 'bg-red-100 text-black dark:bg-red-900/40 dark:text-red-100',
    running: 'bg-blue-100 text-black dark:bg-blue-900/40 dark:text-blue-100',
    pending: 'bg-amber-100 text-black dark:bg-amber-900/40 dark:text-amber-100',
};

const statusClass = computed(() => STATUS_STYLES[props.attempt?.status ?? ''] ?? 'bg-zinc-100 text-black dark:bg-zinc-800 dark:text-zinc-100');

const statusIcon = computed(() => {
    switch (props.attempt?.status) {
        case 'succeeded': return CheckCircle2;
        case 'failed': return XCircle;
        case 'running': return Loader2;
        default: return Clock;
    }
});

const formatTime = (iso: string | null): string => (iso ? new Date(iso).toLocaleString() : '—');

const duration = computed(() => {
    const ms = props.attempt?.duration_ms;
    if (ms === null || ms === undefined) return '—';
    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;
});

/** Human labels for the dispatcher's skip reasons, in the order they are evaluated. */
const ELIGIBILITY_LABELS: Record<string, string> = {
    is_dgepay: 'Checkout URL is a dg-epay link',
    has_reservation_id: 'Link has a reservation ID',
    not_fake: 'Link is not a seeded fake',
    no_callback_yet: 'Payment not already completed',
    not_already_paid: 'Account has not already paid',
    not_expired: 'Checkout link has not expired',
    is_latest_for_account: 'Newest link for this account',
    account_found: 'Matched to an account',
    auto_payment_on: 'Account has auto payment enabled',
    credentials_complete: 'Account has method, wallet and PIN',
};

const eligibilityRows = computed(() =>
    Object.entries(ELIGIBILITY_LABELS).map(([key, label]) => ({
        key,
        label,
        ok: props.eligibility?.[key] ?? false,
    })),
);

const blockingReason = computed(() => eligibilityRows.value.find((r) => !r.ok)?.label ?? null);

// Manual OTP hand-off. The wallet's SIM may not be on an SMS forwarder, in which case the code
// only exists on the handset — typing it here injects it into the channel the driver polls.
const manualOtp = ref('');
const submittingOtp = ref(false);
const otpError = ref<string | null>(null);
const otpNotice = ref<string | null>(null);

const awaitingOtp = computed(() =>
    props.attempt?.status === 'running'
    && ['await_otp', 'submit_wallet'].includes(props.attempt?.stage ?? ''),
);

const submitOtp = () => {
    if (submittingOtp.value || !/^\d{4,8}$/.test(manualOtp.value.trim())) {
        otpError.value = 'Enter the 4-8 digit code from the wallet SMS.';
        return;
    }
    submittingOtp.value = true;
    otpError.value = null;
    otpNotice.value = null;

    router.post(`/payment-links/${props.link.id}/automation-log/otp`, { otp: manualOtp.value.trim() }, {
        preserveScroll: true,
        onSuccess: () => {
            otpNotice.value = 'OTP submitted — the driver picks it up within a few seconds.';
            manualOtp.value = '';
        },
        onError: (errors) => { otpError.value = errors.otp ?? 'Could not submit the OTP.'; },
        onFinish: () => { submittingOtp.value = false; },
    });
};

// Live checkout countdown, mirroring the one on /payment-links: the link is usable for five
// minutes from arrival, after which an unfinished run is stopped and failed.
const nowTick = ref(Date.now());
let tick: ReturnType<typeof setInterval> | undefined;

const expirySecs = computed<number | null>(() => {
    if (!props.link.expires_at) return null;
    return Math.floor((new Date(props.link.expires_at).getTime() - nowTick.value) / 1000);
});

const formatCountdown = (secs: number | null): string => {
    if (secs == null) return '—';
    if (secs <= 0) return 'Expired';
    const m = Math.floor(secs / 60);
    return `${m}:${String(secs % 60).padStart(2, '0')}`;
};

const countdownColor = (secs: number | null): string => {
    if (secs == null) return 'text-zinc-400 dark:text-zinc-600';
    if (secs <= 0) return 'text-red-600 dark:text-red-400';
    if (secs <= 60) return 'text-amber-600 dark:text-amber-400';
    return 'text-emerald-600 dark:text-emerald-400';
};

/**
 * A run takes minutes, so a static page looks frozen. Poll while the attempt is live and stop as
 * soon as it settles, which also stops the polling on a page left open overnight.
 */
const isLive = computed(() => props.attempt?.status === 'running' || props.attempt?.status === 'pending');
const autoRefresh = ref(true);
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    tick = setInterval(() => { nowTick.value = Date.now(); }, 1000);
    timer = setInterval(() => {
        if (autoRefresh.value && isLive.value) {
            router.reload({ only: ['attempt', 'steps', 'link'] });
        }
    }, 3000);
});

onUnmounted(() => {
    if (timer !== undefined) clearInterval(timer);
    if (tick !== undefined) clearInterval(tick);
});
</script>

<template>
    <Head :title="`Auto Payment Log — Link #${link.id}`" />

    <AppLayout>
        <div class="flex flex-col gap-3 p-3">
            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 pb-2 dark:border-zinc-800">
                <div class="flex flex-col gap-0.5">
                    <h1 class="text-sm font-bold">Auto Payment Log</h1>
                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">
                        Link #{{ link.id }} · {{ link.account_phone ?? 'unknown phone' }} · {{ formatTime(link.created_at) }}
                    </span>
                </div>
                <div class="flex items-center gap-1.5">
                    <!-- Checkout window: the same 5-minute countdown shown on /payment-links. -->
                    <span class="flex items-center gap-1 rounded border border-zinc-200 px-2 py-1 dark:border-zinc-800"
                        :title="`Checkout link is usable for ${link.expiry_minutes} minutes from arrival`">
                        <Clock class="h-3.5 w-3.5 text-zinc-400" />
                        <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">time left</span>
                        <span class="font-mono text-[12px] font-bold tabular-nums" :class="countdownColor(expirySecs)">
                            {{ formatCountdown(expirySecs) }}
                        </span>
                    </span>
                    <Badge v-if="link.is_superseded" variant="outline" class="text-[10px] text-amber-600 dark:text-amber-400">superseded</Badge>
                </div>
                <div v-if="attempt" class="flex items-center gap-1.5">
                    <span class="flex items-center gap-1 rounded px-2 py-1 text-[11px] font-bold uppercase tracking-wide" :class="statusClass">
                        <component :is="statusIcon" class="h-3.5 w-3.5" :class="attempt.status === 'running' ? 'animate-spin' : ''" />
                        {{ attempt.status }}
                    </span>
                    <Badge variant="outline" class="text-[10px]">{{ attempt.method_label ?? attempt.method }}</Badge>
                    <Badge variant="outline" class="text-[10px]">try {{ attempt.attempts }}/{{ attempt.max_attempts }}</Badge>
                    <label v-if="isLive" class="flex cursor-pointer items-center gap-1 text-[10px] text-zinc-500 dark:text-zinc-400">
                        <input v-model="autoRefresh" type="checkbox" class="h-3 w-3" />
                        auto-refresh
                    </label>
                </div>
            </div>

            <!-- No attempt: explain why nothing ran -->
            <div v-if="!attempt" class="flex flex-col gap-2 rounded border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-900/50 dark:bg-amber-950/20">
                <div class="flex items-center gap-1.5">
                    <AlertTriangle class="h-4 w-4 text-amber-600 dark:text-amber-400" />
                    <span class="text-[12px] font-bold">No auto payment ran for this link</span>
                </div>
                <p v-if="blockingReason" class="text-[11px] text-zinc-600 dark:text-zinc-300">
                    First unmet condition: <span class="font-semibold">{{ blockingReason }}</span>
                </p>
                <div class="grid gap-1 pt-1">
                    <div v-for="row in eligibilityRows" :key="row.key" class="flex items-center gap-1.5 text-[11px]">
                        <CheckCircle2 v-if="row.ok" class="h-3.5 w-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <XCircle v-else class="h-3.5 w-3.5 shrink-0 text-red-500" />
                        <span :class="row.ok ? 'text-zinc-600 dark:text-zinc-300' : 'font-semibold text-zinc-900 dark:text-zinc-100'">{{ row.label }}</span>
                    </div>
                </div>
            </div>

            <!-- Manual OTP hand-off, shown only while a run is actually waiting for one -->
            <div v-if="awaitingOtp" class="flex flex-col gap-2 rounded border border-blue-200 bg-blue-50/60 p-3 dark:border-blue-900/50 dark:bg-blue-950/20">
                <div class="flex items-center gap-1.5">
                    <KeyRound class="h-4 w-4 text-blue-600 dark:text-blue-400" />
                    <span class="text-[12px] font-bold">Enter the wallet OTP</span>
                </div>
                <p class="text-[11px] text-zinc-600 dark:text-zinc-300">
                    The driver is waiting for the code sent to
                    <span class="font-mono font-semibold">{{ account?.auto_payment_wallet ?? 'the wallet' }}</span>.
                    If that SIM is not on an SMS forwarder the portal never receives it — read it off the handset and type it here.
                </p>
                <form class="flex flex-wrap items-center gap-2" @submit.prevent="submitOtp">
                    <input
                        v-model="manualOtp"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="8"
                        placeholder="123456"
                        class="h-8 w-32 rounded border border-zinc-300 bg-transparent px-2 font-mono text-[13px] tracking-widest dark:border-zinc-700"
                    />
                    <button
                        type="submit"
                        :disabled="submittingOtp"
                        class="flex h-8 items-center gap-1 rounded bg-blue-600 px-3 text-[11px] font-bold text-white transition-colors hover:bg-blue-700 disabled:opacity-50"
                    >
                        <Loader2 v-if="submittingOtp" class="h-3.5 w-3.5 animate-spin" />
                        Submit OTP
                    </button>
                    <span v-if="otpError" class="text-[11px] font-semibold text-red-600 dark:text-red-400">{{ otpError }}</span>
                    <span v-else-if="otpNotice" class="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">{{ otpNotice }}</span>
                </form>
            </div>

            <!-- Step checklist -->
            <div class="flex flex-col gap-1">
                <div class="text-[9px] font-bold uppercase tracking-widest text-zinc-400">Steps</div>
                <ol class="flex flex-col rounded border border-zinc-200 dark:border-zinc-800">
                    <li
                        v-for="(step, i) in steps"
                        :key="step.key"
                        class="flex items-center gap-2 px-2.5 py-1.5"
                        :class="i > 0 ? 'border-t border-zinc-100 dark:border-zinc-800/60' : ''"
                    >
                        <!-- Marker -->
                        <span class="flex h-4 w-4 shrink-0 items-center justify-center">
                            <CheckCircle2 v-if="step.state === 'complete'" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                            <XCircle v-else-if="step.state === 'failed'" class="h-4 w-4 text-red-500" />
                            <span v-else-if="step.state === 'current'" class="flex items-center gap-[3px]">
                                <span
                                    v-for="d in 3"
                                    :key="d"
                                    class="h-1.5 w-1.5 animate-bounce rounded-full bg-blue-500"
                                    :style="{ animationDelay: `${(d - 1) * 150}ms` }"
                                />
                            </span>
                            <span v-else class="h-1.5 w-1.5 rounded-full bg-zinc-300 dark:bg-zinc-700" />
                        </span>

                        <!-- Label -->
                        <span
                            class="text-[11px]"
                            :class="{
                                'text-zinc-500 line-through decoration-emerald-500/70 dark:text-zinc-400': step.state === 'complete',
                                'font-semibold text-blue-700 dark:text-blue-300': step.state === 'current',
                                'font-semibold text-red-600 dark:text-red-400': step.state === 'failed',
                                'text-zinc-400 dark:text-zinc-600': step.state === 'pending',
                            }"
                        >{{ step.label }}</span>

                        <span v-if="step.state === 'current'" class="ml-auto text-[9px] font-bold uppercase tracking-widest text-blue-500">in progress</span>
                        <span v-else-if="step.state === 'failed'" class="ml-auto text-[9px] font-bold uppercase tracking-widest text-red-500">failed here</span>
                    </li>
                </ol>
            </div>

            <!-- Attempt summary -->
            <div v-if="attempt" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded border border-zinc-200 p-2 dark:border-zinc-800">
                    <div class="text-[9px] font-bold uppercase tracking-widest text-zinc-400">Stage reached</div>
                    <div class="text-[12px] font-semibold">{{ attempt.stage ?? '—' }}</div>
                </div>
                <div class="rounded border border-zinc-200 p-2 dark:border-zinc-800">
                    <div class="text-[9px] font-bold uppercase tracking-widest text-zinc-400">Started</div>
                    <div class="text-[12px] font-semibold tabular-nums">{{ formatTime(attempt.started_at) }}</div>
                </div>
                <div class="rounded border border-zinc-200 p-2 dark:border-zinc-800">
                    <div class="text-[9px] font-bold uppercase tracking-widest text-zinc-400">Finished</div>
                    <div class="text-[12px] font-semibold tabular-nums">{{ formatTime(attempt.finished_at) }}</div>
                </div>
                <div class="rounded border border-zinc-200 p-2 dark:border-zinc-800">
                    <div class="text-[9px] font-bold uppercase tracking-widest text-zinc-400">Duration</div>
                    <div class="text-[12px] font-semibold tabular-nums">{{ duration }}</div>
                </div>
            </div>

            <!-- Error -->
            <div v-if="attempt?.last_error" class="rounded border border-red-200 bg-red-50/60 p-3 dark:border-red-900/50 dark:bg-red-950/20">
                <div class="text-[9px] font-bold uppercase tracking-widest text-red-600 dark:text-red-400">Error</div>
                <p class="mt-1 break-words font-mono text-[11px] text-zinc-800 dark:text-zinc-200">{{ attempt.last_error }}</p>
            </div>

            <!-- Payer + link context -->
            <div class="grid gap-2 lg:grid-cols-2">
                <div class="rounded border border-zinc-200 p-2 dark:border-zinc-800">
                    <div class="pb-1.5 text-[9px] font-bold uppercase tracking-widest text-zinc-400">Payer</div>
                    <dl v-if="account" class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[11px]">
                        <dt class="text-zinc-400">Account</dt>
                        <dd class="font-semibold">{{ account.phone }}<span v-if="account.tag" class="ml-1 text-zinc-400">({{ account.tag }})</span></dd>
                        <dt class="text-zinc-400">Auto payment</dt>
                        <dd class="font-semibold">{{ account.auto_payment ? 'Enabled' : 'Disabled' }}</dd>
                        <dt class="text-zinc-400">Method</dt>
                        <dd class="font-semibold">{{ account.auto_payment_method_label ?? '—' }}</dd>
                        <dt class="text-zinc-400">Wallet</dt>
                        <dd class="font-mono font-semibold">{{ account.auto_payment_wallet ?? '—' }}</dd>
                    </dl>
                    <p v-else class="text-[11px] text-zinc-400">No account matched this link's phone.</p>
                </div>

                <div class="rounded border border-zinc-200 p-2 dark:border-zinc-800">
                    <div class="pb-1.5 text-[9px] font-bold uppercase tracking-widest text-zinc-400">Link</div>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[11px]">
                        <dt class="text-zinc-400">Reservation</dt>
                        <dd class="truncate font-mono">{{ link.reservation_id ?? '—' }}</dd>
                        <dt class="text-zinc-400">Callback</dt>
                        <dd class="font-semibold">{{ link.callback_status ?? 'none' }}<span v-if="link.callback_status_code"> ({{ link.callback_status_code }})</span></dd>
                        <dt class="text-zinc-400">Checkout</dt>
                        <dd>
                            <a v-if="link.gateway_page_url" :href="link.gateway_page_url" target="_blank" rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-blue-600 underline underline-offset-2 hover:text-blue-800 dark:text-blue-400">
                                open <ExternalLink class="h-3 w-3" />
                            </a>
                            <span v-else>—</span>
                        </dd>
                    </dl>
                </div>
            </div>

            <!-- Driver console -->
            <div class="flex flex-col gap-1">
                <div class="text-[9px] font-bold uppercase tracking-widest text-zinc-400">Driver console</div>
                <pre v-if="attempt?.driver_log"
                    class="max-h-[26.25rem] overflow-auto rounded border border-zinc-200 bg-zinc-50 p-3 font-mono text-[11px] leading-relaxed text-zinc-900 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-100">{{ attempt.driver_log }}</pre>
                <p v-else class="rounded border border-dashed border-zinc-300 p-3 text-[11px] text-zinc-400 dark:border-zinc-700">
                    No driver output recorded{{ attempt ? ' for this attempt.' : ' — the driver never ran.' }}
                </p>
            </div>
        </div>
    </AppLayout>
</template>
