<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { Copy } from 'lucide-vue-next';
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Switch from '@/components/ui/Switch.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { index as systemConfigIndex } from '@/routes/settings';
import { type BreadcrumbItem } from '@/types';

interface SystemSettings {
    base_url: string | null;
    max_retries: number;
    captcha_fetch_seconds_before_window: number;
    captcha_site_key: string;
    captcha_page_url: string;
    recaptcha_site_key: string;
    recaptcha_page_url: string;
    sign_in_retry_delay_ms: number;
    otp_interval_delay_ms: number;
    otp_timeout_ms: number;
    otp_verify_retry_delay_ms: number;
    reserve_slot_retry_delay_ms: number;
    initiate_payment_retry_delay_ms: number;
    rate_limit_safe_seconds: number;
    window_start_time: string;
    window_end_time: string;
    account_lock_enabled: boolean;
    account_lock_start_time: string | null;
    account_lock_end_time: string | null;
    reserve_slot_id: string;
    payment_config_id: string;
    reserve_request_meta: string;
    signin_429_proxy_url: string | null;
    forgot_password_lead_seconds: number;
    use_java_captcha_generator: boolean;
    captcha_generator_interval_ms: number;
    captcha_generator_max_tokens: number;
    captcha_bot_secret: string | null;
    captcha_shelf_life_ms: number;
}

const form = ref<SystemSettings>({
    base_url: null,
    max_retries: 100,
    captcha_fetch_seconds_before_window: 30,
    captcha_site_key: '0x4AAAAAACghKkJHL1t7UkuZ',
    captcha_page_url: 'https://appointment.ivacbd.com/',
    recaptcha_site_key: '',
    recaptcha_page_url: '',
    sign_in_retry_delay_ms: 2000,
    otp_interval_delay_ms: 3000,
    otp_timeout_ms: 120000,
    otp_verify_retry_delay_ms: 2000,
    reserve_slot_retry_delay_ms: 2000,
    initiate_payment_retry_delay_ms: 2000,
    rate_limit_safe_seconds: 15,
    window_start_time: '00:00:00',
    window_end_time: '23:59:00',
    account_lock_enabled: true,
    account_lock_start_time: '09:00:00',
    account_lock_end_time: '21:00:00',
    reserve_slot_id: '',
    payment_config_id: '',
    reserve_request_meta: '',
    signin_429_proxy_url: null,
    forgot_password_lead_seconds: 120,
    use_java_captcha_generator: false,
    captcha_generator_interval_ms: 5000,
    captcha_generator_max_tokens: 10,
    captcha_bot_secret: null,
    captcha_shelf_life_ms: 20000,
});

const loading = ref(true);
const saving = ref(false);
const recentlySuccessful = ref(false);
const copiedLabel = ref<string | null>(null);
const bangladeshTime = ref<string>('');
let timeInterval: ReturnType<typeof setInterval> | null = null;

const copyLabel = async (text: string, id: string) => {
    try {
        await navigator.clipboard.writeText(text);
        copiedLabel.value = id;
        setTimeout(() => { copiedLabel.value = null; }, 1500);
    } catch (e) {
        console.error('Copy failed', e);
    }
};

const fetchSettings = async () => {
    loading.value = true;
    try {
        const response = await axios.get('/api/settings');
        Object.assign(form.value, response.data);
    } catch (error) {
        console.error('Failed to fetch settings', error);
    } finally {
        loading.value = false;
    }
};

const save = async () => {
    saving.value = true;
    try {
        const response = await axios.post('/api/settings', form.value);
        Object.assign(form.value, response.data);
        recentlySuccessful.value = true;
        setTimeout(() => { recentlySuccessful.value = false; }, 2000);
    } catch (error) {
        console.error('Failed to save settings', error);
    } finally {
        saving.value = false;
    }
};

const updateBangladeshTime = () => {
    const now = new Date();
    bangladeshTime.value = new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Dhaka',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    }).format(now);
};

onMounted(() => {
    fetchSettings();
    updateBangladeshTime();
    timeInterval = setInterval(updateBangladeshTime, 1000);
});

onBeforeUnmount(() => {
    if (timeInterval) {
        clearInterval(timeInterval);
    }
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'System Configuration', href: systemConfigIndex().url },
];

const pad = (n: number) => String(n).padStart(2, '0');

const to12 = (h24: number) => ({
    hour: h24 === 0 ? 12 : h24 > 12 ? h24 - 12 : h24,
    ampm: h24 < 12 ? 'AM' : 'PM',
});

const to24 = (hour12: number, ampm: string) => {
    if (ampm === 'AM') return hour12 === 12 ? 0 : hour12;
    return hour12 === 12 ? 12 : hour12 + 12;
};

const parse24 = (v: string) => {
    const [h = '0', m = '0', s = '0'] = (v ?? '00:00:00').split(':');
    return { h: parseInt(h, 10), m: parseInt(m, 10), s: parseInt(s, 10) };
};

const startParts = computed(() => {
    const { h, m, s } = parse24(form.value.window_start_time);
    return { ...to12(h), m, s };
});

const endParts = computed(() => {
    const { h, m, s } = parse24(form.value.window_end_time);
    return { ...to12(h), m, s };
});

const hours12 = Array.from({ length: 12 }, (_, i) => i + 1);
const minutes = Array.from({ length: 60 }, (_, i) => i);
const seconds = Array.from({ length: 60 }, (_, i) => i);

const setStartPart = (part: 'hour' | 'm' | 's' | 'ampm', val: number | string) => {
    const p = startParts.value;
    const h12 = part === 'hour' ? (val as number) : p.hour;
    const ampm = part === 'ampm' ? (val as string) : p.ampm;
    form.value.window_start_time = `${pad(to24(h12, ampm))}:${pad(part === 'm' ? (val as number) : p.m)}:${pad(part === 's' ? (val as number) : p.s)}`;
};

const setEndPart = (part: 'hour' | 'm' | 's' | 'ampm', val: number | string) => {
    const p = endParts.value;
    const h12 = part === 'hour' ? (val as number) : p.hour;
    const ampm = part === 'ampm' ? (val as string) : p.ampm;
    form.value.window_end_time = `${pad(to24(h12, ampm))}:${pad(part === 'm' ? (val as number) : p.m)}:${pad(part === 's' ? (val as number) : p.s)}`;
};

// Account lock window — same 12-hour picker, over the two account_lock_* columns.
const lockStartParts = computed(() => {
    const { h, m, s } = parse24(form.value.account_lock_start_time ?? '00:00:00');
    return { ...to12(h), m, s };
});

const lockEndParts = computed(() => {
    const { h, m, s } = parse24(form.value.account_lock_end_time ?? '00:00:00');
    return { ...to12(h), m, s };
});

const setLockPart = (which: 'start' | 'end', part: 'hour' | 'm' | 's' | 'ampm', val: number | string) => {
    const p = which === 'start' ? lockStartParts.value : lockEndParts.value;
    const h12 = part === 'hour' ? (val as number) : p.hour;
    const ampm = part === 'ampm' ? (val as string) : p.ampm;
    const next = `${pad(to24(h12, ampm))}:${pad(part === 'm' ? (val as number) : p.m)}:${pad(part === 's' ? (val as number) : p.s)}`;
    if (which === 'start') {
        form.value.account_lock_start_time = next;
    } else {
        form.value.account_lock_end_time = next;
    }
};

/** Start later than end wraps past midnight; identical times mean the lock never applies. */
const lockSummary = computed(() => {
    const start = form.value.account_lock_start_time ?? '';
    const end = form.value.account_lock_end_time ?? '';
    if (!form.value.account_lock_enabled) return 'Disabled — agents can edit and delete at any time.';
    if (!start || !end || start === end) return 'No range set — agents can edit and delete at any time.';
    const wraps = start > end ? ' (crosses midnight)' : '';
    return `Agents cannot edit or delete accounts between ${start} and ${end} BDT${wraps}. Managers and admins are unaffected.`;
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="System Configuration" />
        <h1 class="sr-only">System Configuration</h1>

        <SettingsLayout>
            <div class="flex flex-col gap-3">
                <Heading title="System Configuration" description="Adjust global system parameters and operational defaults" />

                <!-- Skeleton -->
                <div v-if="loading" class="grid grid-cols-1 xl:grid-cols-2 gap-3">
                    <Card v-for="i in 4" :key="i" class="shadow-none rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm">
                        <CardHeader class="pb-1.5 p-3">
                            <div class="h-4 w-1/3 animate-pulse rounded bg-muted"></div>
                            <div class="h-3 w-1/2 animate-pulse rounded bg-muted mt-1.5"></div>
                        </CardHeader>
                        <CardContent class="p-3 grid gap-1.5">
                            <div v-for="j in 3" :key="j" class="h-8 w-full animate-pulse rounded bg-muted"></div>
                        </CardContent>
                    </Card>
                </div>

                <div v-else class="flex flex-col gap-3">

                    <!-- Row 1: URLs (full width) -->
                    <Card class="shadow-none rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm">
                        <CardHeader class="pb-1.5 p-3">
                            <CardTitle class="text-[12px] font-semibold uppercase tracking-widest">API Endpoints</CardTitle>
                            <CardDescription class="text-[10px] mt-0.5">Core URLs used by the Java bot</CardDescription>
                        </CardHeader>
                        <CardContent class="p-3 grid gap-2">
                            <div class="grid gap-1.5">
                                <div class="flex items-center gap-1.5">
                                    <Label for="base_url" class="text-[11px] font-semibold">Visa Booking API Base URL</Label>
                                    <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('base_url', 'base_url')">
                                        <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'base_url' ? 'text-green-500' : 'text-muted-foreground'" />
                                    </Button>
                                </div>
                                <Input id="base_url" v-model="form.base_url" placeholder="https://api.ivacbd.com/iams/api/v1" class="h-8 text-[11px]" />
                                <p class="text-[10px] text-muted-foreground">The root endpoint for all booking API requests (sign-in, OTP verification, slot reservation, payment)</p>
                            </div>
                            <div class="grid gap-1.5">
                                <div class="flex items-center gap-1.5">
                                    <Label for="reserve_slot_id" class="text-[11px] font-semibold">Reserve Slot ID</Label>
                                    <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('reserve_slot_id', 'reserve_slot_id')">
                                        <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'reserve_slot_id' ? 'text-green-500' : 'text-muted-foreground'" />
                                    </Button>
                                </div>
                                <Input id="reserve_slot_id" v-model="form.reserve_slot_id" placeholder="ccd3dd63-e781-48ba-a48d-c65eaa4fc663" class="h-8 text-[11px] font-mono" />
                                <p class="text-[10px] text-muted-foreground">Fixed slot ID embedded in the reserve URL (POST /slots/&#123;id&#125;/reserve-slot). IVAC bakes it into its bundle and rotates it on redeploy — update here when reserve starts returning 404.</p>
                            </div>
                            <div class="grid gap-1.5">
                                <div class="flex items-center gap-1.5">
                                    <Label for="payment_config_id" class="text-[11px] font-semibold">Payment Config ID</Label>
                                    <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('payment_config_id', 'payment_config_id')">
                                        <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'payment_config_id' ? 'text-green-500' : 'text-muted-foreground'" />
                                    </Button>
                                </div>
                                <Input id="payment_config_id" v-model="form.payment_config_id" placeholder="f2a2fcd1-4019-4291-ba2c-ea94a60ea54f" class="h-8 text-[11px] font-mono" />
                                <p class="text-[10px] text-muted-foreground">Fixed config ID embedded in the payment URL (POST /payment/&#123;id&#125;/dg-epay/initiate). IVAC bakes it into its bundle and rotates it on redeploy — auto-synced by the Algorithm Monitor; update here when payment starts returning 404.</p>
                            </div>
                            <div class="grid gap-1.5">
                                <div class="flex items-center gap-1.5">
                                    <Label for="reserve_request_meta" class="text-[11px] font-semibold">Reserve Request Meta</Label>
                                    <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('reserve_request_meta', 'reserve_request_meta')">
                                        <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'reserve_request_meta' ? 'text-green-500' : 'text-muted-foreground'" />
                                    </Button>
                                </div>
                                <Input id="reserve_request_meta" v-model="form.reserve_request_meta" placeholder="windos.s" class="h-8 text-[11px] font-mono" />
                                <p class="text-[10px] text-muted-foreground">Value of the x-v-request-meta header sent on reserve-slot. IVAC bakes it into its bundle and rotates it on redeploy — auto-synced by the Algorithm Monitor; update here if reserve starts failing header-side.</p>
                            </div>
                            <div class="grid gap-1.5">
                                <div class="flex items-center gap-1.5">
                                    <Label for="signin_429_proxy_url" class="text-[11px] font-semibold">Sign-in 429 Fallback Proxy</Label>
                                    <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('signin_429_proxy_url', 'signin_429_proxy_url')">
                                        <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'signin_429_proxy_url' ? 'text-green-500' : 'text-muted-foreground'" />
                                    </Button>
                                </div>
                                <Input id="signin_429_proxy_url" v-model="form.signin_429_proxy_url" placeholder="http://user:pass@host:port" class="h-8 text-[11px] font-mono" />
                                <p class="text-[10px] text-muted-foreground">Forward proxy the bot routes sign-in through when IVAC's edge/WAF throttles the worker's own IP (429 "Too many request detected", no server-stated cooldown). Blank disables the fallback — the bot just backs off as before.</p>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Row 2: Two-column cards -->
                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-3">

                        <!-- Retry & Timeout -->
                        <Card class="shadow-none rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm">
                            <CardHeader class="pb-1.5 p-3">
                                <CardTitle class="text-[12px] font-semibold uppercase tracking-widest">Retry &amp; Timeout</CardTitle>
                                <CardDescription class="text-[10px] mt-0.5">Global defaults — per-account values override these</CardDescription>
                            </CardHeader>
                            <CardContent class="p-3 grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="max_retries" class="text-[11px] font-semibold">Maximum Retry Attempts</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('max_retries', 'max_retries')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'max_retries' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <Input id="max_retries" type="number" v-model.number="form.max_retries" min="1" class="h-8 text-[11px]" />
                                    <p class="text-[10px] text-muted-foreground">Default maximum retries for any operation (can be overridden per account)</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="sign_in_retry_delay_ms" class="text-[11px] font-semibold">Delay Between Sign-in Attempts</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('sign_in_retry_delay_ms', 'sign_in_retry_delay_ms')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'sign_in_retry_delay_ms' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <Input id="sign_in_retry_delay_ms" type="number" v-model.number="form.sign_in_retry_delay_ms" min="0" class="h-8 text-[11px]" />
                                    <p class="text-[10px] text-muted-foreground">Wait time before retrying a failed sign-in (milliseconds)</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="otp_verify_retry_delay_ms" class="text-[11px] font-semibold">Delay Between OTP Verification Attempts</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('otp_verify_retry_delay_ms', 'otp_verify_retry_delay_ms')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'otp_verify_retry_delay_ms' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <Input id="otp_verify_retry_delay_ms" type="number" v-model.number="form.otp_verify_retry_delay_ms" min="0" class="h-8 text-[11px]" />
                                    <p class="text-[10px] text-muted-foreground">Wait time before retrying OTP verification after incorrect code (milliseconds)</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="reserve_slot_retry_delay_ms" class="text-[11px] font-semibold">Delay Between Slot Reservation Attempts</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('reserve_slot_retry_delay_ms', 'reserve_slot_retry_delay_ms')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'reserve_slot_retry_delay_ms' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <Input id="reserve_slot_retry_delay_ms" type="number" v-model.number="form.reserve_slot_retry_delay_ms" min="0" class="h-8 text-[11px]" />
                                    <p class="text-[10px] text-muted-foreground">Wait time before retrying slot reservation after timeout or rate-limit (milliseconds)</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="initiate_payment_retry_delay_ms" class="text-[11px] font-semibold">Delay Between Payment Initiation Attempts</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('initiate_payment_retry_delay_ms', 'initiate_payment_retry_delay_ms')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'initiate_payment_retry_delay_ms' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <Input id="initiate_payment_retry_delay_ms" type="number" v-model.number="form.initiate_payment_retry_delay_ms" min="0" class="h-8 text-[11px]" />
                                    <p class="text-[10px] text-muted-foreground">Wait time before retrying payment initiation after failure (milliseconds)</p>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- OTP & Captcha -->
                        <Card class="shadow-none rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm">
                            <CardHeader class="pb-1.5 p-3">
                                <CardTitle class="text-[12px] font-semibold uppercase tracking-widest">OTP &amp; Captcha</CardTitle>
                                <CardDescription class="text-[10px] mt-0.5">Firebase polling and captcha prefetch timing</CardDescription>
                            </CardHeader>
                            <CardContent class="p-3 grid gap-1.5">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                                    <div class="grid gap-1.5">
                                        <div class="flex items-center gap-1.5">
                                            <Label for="otp_interval_delay_ms" class="text-[11px] font-semibold">Firebase OTP Polling Interval</Label>
                                            <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('otp_interval_delay_ms', 'otp_interval_delay_ms')">
                                                <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'otp_interval_delay_ms' ? 'text-green-500' : 'text-muted-foreground'" />
                                            </Button>
                                        </div>
                                        <Input id="otp_interval_delay_ms" type="number" v-model.number="form.otp_interval_delay_ms" min="100" class="h-8 text-[11px]" />
                                        <p class="text-[10px] text-muted-foreground">How often to check Firebase for new OTP codes (milliseconds)</p>
                                    </div>
                                    <div class="grid gap-1.5">
                                        <div class="flex items-center gap-1.5">
                                            <Label for="otp_timeout_ms" class="text-[11px] font-semibold">Maximum OTP Wait Time</Label>
                                            <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('otp_timeout_ms', 'otp_timeout_ms')">
                                                <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'otp_timeout_ms' ? 'text-green-500' : 'text-muted-foreground'" />
                                            </Button>
                                        </div>
                                        <Input id="otp_timeout_ms" type="number" v-model.number="form.otp_timeout_ms" min="1000" class="h-8 text-[11px]" />
                                        <p class="text-[10px] text-muted-foreground">Give up waiting for OTP after this duration (milliseconds)</p>
                                    </div>
                                </div>
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="captcha_fetch_seconds_before_window" class="text-[11px] font-semibold">Captcha Token Prefetch Timing</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('captcha_fetch_seconds_before_window', 'captcha_fetch_seconds_before_window')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'captcha_fetch_seconds_before_window' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <Input id="captcha_fetch_seconds_before_window" type="number" v-model.number="form.captcha_fetch_seconds_before_window" min="0" class="h-8 text-[11px]" />
                                    <p class="text-[10px] text-muted-foreground">Fetch captcha token this many seconds before the booking window opens (used during normal auth flow fallback)</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="captcha_shelf_life_ms" class="text-[11px] font-semibold">Captcha Token Shelf Life</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel(String(form.captcha_shelf_life_ms), 'captcha_shelf_life_ms')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'captcha_shelf_life_ms' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <Input id="captcha_shelf_life_ms" type="number" v-model.number="form.captcha_shelf_life_ms" min="1000" class="h-8 text-[11px]" />
                                    <p class="text-[10px] text-muted-foreground">Max age of a solved captcha token before the bot fetches a fresh one (milliseconds). Applies to both sign-in and slot reservation.</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="captcha_site_key" class="text-[11px] font-semibold">Captcha Site Key</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('captcha_site_key', 'captcha_site_key')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'captcha_site_key' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <Input id="captcha_site_key" v-model="form.captcha_site_key" placeholder="0x4AAAAAACghKkJHL1t7UkuZ" class="h-8 text-[11px]" />
                                    <p class="text-[10px] text-muted-foreground">Cloudflare Turnstile site key for Captcha automation (must match your booking site configuration)</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="captcha_page_url" class="text-[11px] font-semibold">Turnstile Page URL</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('captcha_page_url', 'captcha_page_url')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'captcha_page_url' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <Input id="captcha_page_url" v-model="form.captcha_page_url" type="url" placeholder="https://appointment.ivacbd.com/" class="h-8 text-[11px]" />
                                    <p class="text-[10px] text-muted-foreground">Page URL for Cloudflare Turnstile solving (raw and encrypted types)</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="recaptcha_site_key" class="text-[11px] font-semibold">reCAPTCHA v2 Site Key</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('recaptcha_site_key', 'recaptcha_site_key')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'recaptcha_site_key' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <Input id="recaptcha_site_key" v-model="form.recaptcha_site_key" placeholder="6Lxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" class="h-8 text-[11px]" />
                                    <p class="text-[10px] text-muted-foreground">Google reCAPTCHA v2 site key (for IODP slot reservation)</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="recaptcha_page_url" class="text-[11px] font-semibold">reCAPTCHA v2 Page URL</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('recaptcha_page_url', 'recaptcha_page_url')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'recaptcha_page_url' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <Input id="recaptcha_page_url" v-model="form.recaptcha_page_url" type="url" placeholder="https://appointment.ivacbd.com/" class="h-8 text-[11px]" />
                                    <p class="text-[10px] text-muted-foreground">Page URL for Google reCAPTCHA v2 solving (IODP slot reservation)</p>
                                </div>
                            </CardContent>
                        </Card>

                    </div>

                    <!-- Row 3: Booking Window & Connection Warmup -->
                    <Card class="shadow-none rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm">
                        <CardHeader class="pb-1.5 p-3">
                            <CardTitle class="text-[12px] font-semibold uppercase tracking-widest">Booking Window &amp; Warmup</CardTitle>
                            <CardDescription class="text-[10px] mt-0.5">Appointment booking window (Dhaka time, 24-hour format) | Current Bangladesh Time: <span class="font-semibold text-foreground">{{ bangladeshTime }}</span></CardDescription>
                        </CardHeader>
                        <CardContent class="p-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-1.5">
                            <div class="grid gap-1.5">
                                <div class="flex items-center gap-1.5">
                                    <Label for="window_start_time" class="text-[11px] font-semibold">Opens At</Label>
                                    <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('window_start_time', 'window_start_time')">
                                        <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'window_start_time' ? 'text-green-500' : 'text-muted-foreground'" />
                                    </Button>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <div class="flex items-center gap-1">
                                        <select :value="startParts.hour" @change="setStartPart('hour', +($event.target as HTMLSelectElement).value)" class="h-8 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[11px] font-mono text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-1 focus:ring-ring">
                                            <option v-for="h in hours12" :key="h" :value="h">{{ pad(h) }}</option>
                                        </select>
                                        <span class="text-[11px] text-muted-foreground font-mono">:</span>
                                        <select :value="startParts.m" @change="setStartPart('m', +($event.target as HTMLSelectElement).value)" class="h-8 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[11px] font-mono text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-1 focus:ring-ring">
                                            <option v-for="x in minutes" :key="x" :value="x">{{ pad(x) }}</option>
                                        </select>
                                        <span class="text-[11px] text-muted-foreground font-mono">:</span>
                                        <select :value="startParts.s" @change="setStartPart('s', +($event.target as HTMLSelectElement).value)" class="h-8 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[11px] font-mono text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-1 focus:ring-ring">
                                            <option v-for="x in seconds" :key="x" :value="x">{{ pad(x) }}</option>
                                        </select>
                                    </div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <label class="flex items-center gap-1 cursor-pointer">
                                            <input type="radio" :checked="startParts.ampm === 'AM'" @change="setStartPart('ampm', 'AM')" class="accent-emerald-600" />
                                            <span class="text-[11px] font-semibold">AM</span>
                                        </label>
                                        <label class="flex items-center gap-1 cursor-pointer">
                                            <input type="radio" :checked="startParts.ampm === 'PM'" @change="setStartPart('ampm', 'PM')" class="accent-emerald-600" />
                                            <span class="text-[11px] font-semibold">PM</span>
                                        </label>
                                        <span class="text-[10px] text-muted-foreground font-mono">{{ form.window_start_time }} BDT</span>
                                    </div>
                                </div>
                            </div>
                            <div class="grid gap-1.5">
                                <div class="flex items-center gap-1.5">
                                    <Label for="window_end_time" class="text-[11px] font-semibold">Closes At</Label>
                                    <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('window_end_time', 'window_end_time')">
                                        <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'window_end_time' ? 'text-green-500' : 'text-muted-foreground'" />
                                    </Button>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <div class="flex items-center gap-1">
                                        <select :value="endParts.hour" @change="setEndPart('hour', +($event.target as HTMLSelectElement).value)" class="h-8 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[11px] font-mono text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-1 focus:ring-ring">
                                            <option v-for="h in hours12" :key="h" :value="h">{{ pad(h) }}</option>
                                        </select>
                                        <span class="text-[11px] text-muted-foreground font-mono">:</span>
                                        <select :value="endParts.m" @change="setEndPart('m', +($event.target as HTMLSelectElement).value)" class="h-8 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[11px] font-mono text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-1 focus:ring-ring">
                                            <option v-for="x in minutes" :key="x" :value="x">{{ pad(x) }}</option>
                                        </select>
                                        <span class="text-[11px] text-muted-foreground font-mono">:</span>
                                        <select :value="endParts.s" @change="setEndPart('s', +($event.target as HTMLSelectElement).value)" class="h-8 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[11px] font-mono text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-1 focus:ring-ring">
                                            <option v-for="x in seconds" :key="x" :value="x">{{ pad(x) }}</option>
                                        </select>
                                    </div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <label class="flex items-center gap-1 cursor-pointer">
                                            <input type="radio" :checked="endParts.ampm === 'AM'" @change="setEndPart('ampm', 'AM')" class="accent-emerald-600" />
                                            <span class="text-[11px] font-semibold">AM</span>
                                        </label>
                                        <label class="flex items-center gap-1 cursor-pointer">
                                            <input type="radio" :checked="endParts.ampm === 'PM'" @change="setEndPart('ampm', 'PM')" class="accent-emerald-600" />
                                            <span class="text-[11px] font-semibold">PM</span>
                                        </label>
                                        <span class="text-[10px] text-muted-foreground font-mono">{{ form.window_end_time }} BDT</span>
                                    </div>
                                </div>
                            </div>
                            <div class="grid gap-1.5">
                                <div class="flex items-center gap-1.5">
                                    <Label for="rate_limit_safe_seconds" class="text-[11px] font-semibold">429 Backoff</Label>
                                    <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('rate_limit_safe_seconds', 'rate_limit_safe_seconds')">
                                        <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'rate_limit_safe_seconds' ? 'text-green-500' : 'text-muted-foreground'" />
                                    </Button>
                                </div>
                                <Input id="rate_limit_safe_seconds" type="number" v-model.number="form.rate_limit_safe_seconds" min="0" class="h-8 text-[11px]" />
                                <p class="text-[10px] text-muted-foreground">Seconds for 429 backoff</p>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Row 3b: Account Lock Window -->
                    <Card class="shadow-none rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm">
                        <CardHeader class="pb-1.5 p-3">
                            <CardTitle class="text-[12px] font-semibold uppercase tracking-widest">Account Lock Window</CardTitle>
                            <CardDescription class="text-[10px] mt-0.5">Daily window in which agents cannot edit or delete accounts (Dhaka time) | Current Bangladesh Time: <span class="font-semibold text-foreground">{{ bangladeshTime }}</span></CardDescription>
                        </CardHeader>
                        <CardContent class="p-3 grid gap-1.5">
                            <div class="flex items-center gap-2">
                                <Switch id="account_lock_enabled" v-model="form.account_lock_enabled" />
                                <div class="flex items-center gap-1.5">
                                    <Label for="account_lock_enabled" class="text-[11px] font-semibold">Enable Account Lock</Label>
                                    <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('account_lock_enabled', 'account_lock_enabled')">
                                        <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'account_lock_enabled' ? 'text-green-500' : 'text-muted-foreground'" />
                                    </Button>
                                </div>
                            </div>
                            <p class="text-[10px] text-muted-foreground">{{ lockSummary }}</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="account_lock_start_time" class="text-[11px] font-semibold">Locks At</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('account_lock_start_time', 'account_lock_start_time')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'account_lock_start_time' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <div class="flex items-center gap-1">
                                            <select :value="lockStartParts.hour" :disabled="!form.account_lock_enabled" @change="setLockPart('start', 'hour', +($event.target as HTMLSelectElement).value)" class="h-8 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[11px] font-mono text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50">
                                                <option v-for="h in hours12" :key="h" :value="h">{{ pad(h) }}</option>
                                            </select>
                                            <span class="text-[11px] text-muted-foreground font-mono">:</span>
                                            <select :value="lockStartParts.m" :disabled="!form.account_lock_enabled" @change="setLockPart('start', 'm', +($event.target as HTMLSelectElement).value)" class="h-8 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[11px] font-mono text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50">
                                                <option v-for="x in minutes" :key="x" :value="x">{{ pad(x) }}</option>
                                            </select>
                                            <span class="text-[11px] text-muted-foreground font-mono">:</span>
                                            <select :value="lockStartParts.s" :disabled="!form.account_lock_enabled" @change="setLockPart('start', 's', +($event.target as HTMLSelectElement).value)" class="h-8 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[11px] font-mono text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50">
                                                <option v-for="x in seconds" :key="x" :value="x">{{ pad(x) }}</option>
                                            </select>
                                        </div>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <label class="flex items-center gap-1 cursor-pointer">
                                                <input type="radio" :checked="lockStartParts.ampm === 'AM'" :disabled="!form.account_lock_enabled" @change="setLockPart('start', 'ampm', 'AM')" class="accent-emerald-600" />
                                                <span class="text-[11px] font-semibold">AM</span>
                                            </label>
                                            <label class="flex items-center gap-1 cursor-pointer">
                                                <input type="radio" :checked="lockStartParts.ampm === 'PM'" :disabled="!form.account_lock_enabled" @change="setLockPart('start', 'ampm', 'PM')" class="accent-emerald-600" />
                                                <span class="text-[11px] font-semibold">PM</span>
                                            </label>
                                            <span class="text-[10px] text-muted-foreground font-mono">{{ form.account_lock_start_time }} BDT</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="account_lock_end_time" class="text-[11px] font-semibold">Unlocks At</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('account_lock_end_time', 'account_lock_end_time')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'account_lock_end_time' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <div class="flex items-center gap-1">
                                            <select :value="lockEndParts.hour" :disabled="!form.account_lock_enabled" @change="setLockPart('end', 'hour', +($event.target as HTMLSelectElement).value)" class="h-8 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[11px] font-mono text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50">
                                                <option v-for="h in hours12" :key="h" :value="h">{{ pad(h) }}</option>
                                            </select>
                                            <span class="text-[11px] text-muted-foreground font-mono">:</span>
                                            <select :value="lockEndParts.m" :disabled="!form.account_lock_enabled" @change="setLockPart('end', 'm', +($event.target as HTMLSelectElement).value)" class="h-8 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[11px] font-mono text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50">
                                                <option v-for="x in minutes" :key="x" :value="x">{{ pad(x) }}</option>
                                            </select>
                                            <span class="text-[11px] text-muted-foreground font-mono">:</span>
                                            <select :value="lockEndParts.s" :disabled="!form.account_lock_enabled" @change="setLockPart('end', 's', +($event.target as HTMLSelectElement).value)" class="h-8 rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-1.5 text-[11px] font-mono text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50">
                                                <option v-for="x in seconds" :key="x" :value="x">{{ pad(x) }}</option>
                                            </select>
                                        </div>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <label class="flex items-center gap-1 cursor-pointer">
                                                <input type="radio" :checked="lockEndParts.ampm === 'AM'" :disabled="!form.account_lock_enabled" @change="setLockPart('end', 'ampm', 'AM')" class="accent-emerald-600" />
                                                <span class="text-[11px] font-semibold">AM</span>
                                            </label>
                                            <label class="flex items-center gap-1 cursor-pointer">
                                                <input type="radio" :checked="lockEndParts.ampm === 'PM'" :disabled="!form.account_lock_enabled" @change="setLockPart('end', 'ampm', 'PM')" class="accent-emerald-600" />
                                                <span class="text-[11px] font-semibold">PM</span>
                                            </label>
                                            <span class="text-[10px] text-muted-foreground font-mono">{{ form.account_lock_end_time }} BDT</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Row 4: OTP Pre-staging -->
                    <Card class="shadow-none rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm">
                        <CardHeader class="pb-1.5 p-3">
                            <CardTitle class="text-[12px] font-semibold uppercase tracking-widest">OTP Pre-staging</CardTitle>
                            <CardDescription class="text-[10px] mt-0.5">Send OTP via forgot-password endpoint before booking window opens (works during maintenance)</CardDescription>
                        </CardHeader>
                        <CardContent class="p-3 grid gap-1.5">
                            <div class="grid gap-1.5">
                                <div class="flex items-center gap-1.5">
                                    <Label for="forgot_password_lead_seconds" class="text-[11px] font-semibold">Send OTP Lead Time</Label>
                                    <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('forgot_password_lead_seconds', 'forgot_password_lead_seconds')">
                                        <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'forgot_password_lead_seconds' ? 'text-green-500' : 'text-muted-foreground'" />
                                    </Button>
                                </div>
                                <Input id="forgot_password_lead_seconds" type="number" v-model.number="form.forgot_password_lead_seconds" min="0" class="h-8 text-[11px]" />
                                <p class="text-[10px] text-muted-foreground">Call forgot-password endpoint this many seconds before the window opens</p>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Row 5: Java Captcha Generator -->
                    <Card class="shadow-none rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm">
                        <CardHeader class="pb-1.5 p-3">
                            <CardTitle class="text-[12px] font-semibold uppercase tracking-widest">Java Captcha Generator</CardTitle>
                            <CardDescription class="text-[10px] mt-0.5">Let the Java bot solve captchas directly via solver APIs and push tokens to the pool — no browser needed</CardDescription>
                        </CardHeader>
                        <CardContent class="p-3 grid gap-1.5">
                            <div class="flex items-center gap-2">
                                <Switch id="use_java_captcha_generator" v-model="form.use_java_captcha_generator" />
                                <div class="flex items-center gap-1.5">
                                    <Label for="use_java_captcha_generator" class="text-[11px] font-semibold">Enable Java Captcha Generator</Label>
                                    <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('use_java_captcha_generator', 'use_java_captcha_generator')">
                                        <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'use_java_captcha_generator' ? 'text-green-500' : 'text-muted-foreground'" />
                                    </Button>
                                </div>
                            </div>
                            <p class="text-[10px] text-muted-foreground">When enabled, the bot spawns a background thread that round-robins through enabled captcha providers, solves tokens, and pushes them to the pool automatically</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="captcha_generator_interval_ms" class="text-[11px] font-semibold">Generation Interval</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('captcha_generator_interval_ms', 'captcha_generator_interval_ms')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'captcha_generator_interval_ms' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <Input id="captcha_generator_interval_ms" type="number" v-model.number="form.captcha_generator_interval_ms" min="1000" :disabled="!form.use_java_captcha_generator" class="h-8 text-[11px]" />
                                    <p class="text-[10px] text-muted-foreground">Wait between solve attempts per provider (ms)</p>
                                </div>
                                <div class="grid gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <Label for="captcha_generator_max_tokens" class="text-[11px] font-semibold">Max Token Pool Size</Label>
                                        <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('captcha_generator_max_tokens', 'captcha_generator_max_tokens')">
                                            <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'captcha_generator_max_tokens' ? 'text-green-500' : 'text-muted-foreground'" />
                                        </Button>
                                    </div>
                                    <Input id="captcha_generator_max_tokens" type="number" v-model.number="form.captcha_generator_max_tokens" min="1" :disabled="!form.use_java_captcha_generator" class="h-8 text-[11px]" />
                                    <p class="text-[10px] text-muted-foreground">Max tokens to keep in the pool</p>
                                </div>
                            </div>
                            <div class="grid gap-1.5">
                                <div class="flex items-center gap-1.5">
                                    <Label for="captcha_bot_secret" class="text-[11px] font-semibold">Bot Secret</Label>
                                    <Button variant="ghost" size="sm" class="h-4 w-4 p-0" @click="copyLabel('captcha_bot_secret', 'captcha_bot_secret')">
                                        <Copy class="h-2.5 w-2.5" :class="copiedLabel === 'captcha_bot_secret' ? 'text-green-500' : 'text-muted-foreground'" />
                                    </Button>
                                </div>
                                <Input id="captcha_bot_secret" v-model="form.captcha_bot_secret" placeholder="Set a secret to authenticate bot-to-server requests" class="h-8 text-[11px]" />
                                <p class="text-[10px] text-muted-foreground">Shared secret sent as <code class="font-mono text-[9px]">X-Bot-Secret</code> header on <code class="font-mono text-[9px]">/api/captcha/bot-providers</code> and <code class="font-mono text-[9px]">/api/captcha/bot-store</code></p>
                            </div>
                        </CardContent>
                    </Card>

                </div>

                <div v-if="!loading" class="flex items-center gap-2 pt-2">
                    <Button :disabled="loading || saving" @click="save" size="sm" class="h-8 text-[11px] shadow-sm bg-emerald-600 hover:bg-emerald-700">
                        {{ saving ? 'Saving…' : 'Save All' }}
                    </Button>
                    <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out" leave-to-class="opacity-0">
                        <p v-show="recentlySuccessful" class="text-[10px] text-green-600 font-medium">
                            Settings saved successfully.
                        </p>
                    </Transition>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
