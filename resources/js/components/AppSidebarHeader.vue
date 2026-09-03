<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { Loader2, LogOut } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import DhakaTimeDisplay from '@/components/DhakaTimeDisplay.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const isSuperAdmin = computed(() => page.props.auth.permissions?.['bot.manage'] === true);

/** Set while signed in as somebody else; offers the way back to the original account. */
const isImpersonating = computed(() => (page.props.auth as any)?.impersonating === true);
const impersonatedName = computed(() => (page.props.auth as any)?.user?.name ?? '');

function stopImpersonating() {
    router.post('/impersonate/stop');
}

const POLL_INTERVAL_MS = 15_000;

const captchaEnabled = ref<boolean | null>(null);
const captchaBusy = ref(false);

const generatorRunning = ref<boolean | null>(null);
const generatorReady = ref(0);
const generatorBusy = ref(false);

let pollTimer: ReturnType<typeof setInterval> | null = null;

async function loadCaptchaState() {
    if (!isSuperAdmin.value) return;
    try {
        const res = await axios.get('/api/captcha-providers');
        const providers: { enabled: boolean }[] = res.data?.data ?? res.data ?? [];
        captchaEnabled.value = providers.some((p) => p.enabled);
    } catch {
        // silently ignore — non-critical header widget
    }
}

/**
 * Reflects the pool filler service (ipms-captcha-pool), which is what actually
 * generates tokens. It is independent of provider enablement, so both states
 * are surfaced separately.
 */
async function loadGeneratorState() {
    if (!isSuperAdmin.value) return;
    try {
        const res = await axios.get('/api/captcha/control/status');
        generatorRunning.value = res.data?.running === true;
        generatorReady.value = Number(res.data?.pool_ready ?? 0);
    } catch {
        // silently ignore — non-critical header widget
    }
}

async function toggleCaptcha() {
    if (captchaEnabled.value === null || captchaBusy.value) return;
    const next = !captchaEnabled.value;
    captchaBusy.value = true;
    try {
        await axios.post('/api/captcha-providers/bulk-status', { enabled: next });
        captchaEnabled.value = next;
    } catch {
        // silently ignore
    } finally {
        captchaBusy.value = false;
    }
}

async function toggleGenerator() {
    if (generatorRunning.value === null || generatorBusy.value) return;
    generatorBusy.value = true;
    try {
        await axios.post(generatorRunning.value ? '/api/captcha/control/stop' : '/api/captcha/control/start');
    } catch {
        // 409 already_running/not_running just means we were out of sync — the
        // refresh below reconciles it either way.
    } finally {
        await loadGeneratorState();
        generatorBusy.value = false;
    }
}

onMounted(() => {
    loadCaptchaState();
    loadGeneratorState();

    if (isSuperAdmin.value) {
        pollTimer = setInterval(loadGeneratorState, POLL_INTERVAL_MS);
    }
});

onBeforeUnmount(() => {
    if (pollTimer) {
        clearInterval(pollTimer);
    }
});
</script>

<template>
    <header
        class="flex h-12 shrink-0 items-center gap-1.5 border-b border-sidebar-border/70 px-3 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 sm:h-16 sm:gap-2 sm:px-6 md:px-4"
    >
        <div class="flex min-w-0 shrink-0 items-center gap-1.5 sm:gap-2">
            <SidebarTrigger class="-ml-1 shrink-0" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <div class="hidden min-w-0 sm:block">
                    <Breadcrumbs :breadcrumbs="breadcrumbs" />
                </div>
            </template>
        </div>

        <div class="flex min-w-0 flex-1 justify-center overflow-hidden">
            <DhakaTimeDisplay />
        </div>

        <div class="flex shrink-0 items-center gap-1 sm:gap-1.5">
            <button
                v-if="isImpersonating"
                @click="stopImpersonating"
                :title="`Signed in as ${impersonatedName} — click to return to your own account`"
                class="flex items-center gap-1 rounded-full border border-amber-300 bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800 transition-colors hover:bg-amber-200 dark:border-amber-700/60 dark:bg-amber-900/30 dark:text-amber-300 dark:hover:bg-amber-900/50 sm:gap-1.5 sm:px-3 sm:py-1 sm:text-xs"
            >
                <LogOut class="h-3 w-3" />
                <span class="hidden sm:inline">Viewing as {{ impersonatedName }} — Exit</span>
                <span class="sm:hidden">Exit</span>
            </button>
            <button
                v-if="isSuperAdmin && captchaEnabled !== null"
                :disabled="captchaBusy"
                @click="toggleCaptcha"
                :title="captchaEnabled ? 'Captcha providers enabled — click to disable all' : 'Captcha providers disabled — click to enable all'"
                :class="[
                    'flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[10px] font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed sm:gap-1.5 sm:px-3 sm:py-1 sm:text-xs',
                    captchaEnabled
                        ? 'border-emerald-300 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-100 dark:hover:bg-emerald-900/40'
                        : 'border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/40',
                ]"
            >
                <Loader2 v-if="captchaBusy" class="size-2.5 animate-spin sm:size-3" />
                <span v-else :class="['size-1.5 rounded-full sm:size-2', captchaEnabled ? 'bg-emerald-500' : 'bg-red-500']" />
                <span class="sm:hidden">Prov</span><span class="hidden sm:inline">Provider</span> {{ captchaEnabled ? 'ON' : 'OFF' }}
            </button>

            <button
                v-if="isSuperAdmin && generatorRunning !== null"
                :disabled="generatorBusy"
                @click="toggleGenerator"
                :title="
                    generatorRunning
                        ? `Token generation running — ${generatorReady} ready in pool. Click to stop.`
                        : 'Token generation stopped — no captchas are being solved. Click to start.'
                "
                :class="[
                    'flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[10px] font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed sm:gap-1.5 sm:px-3 sm:py-1 sm:text-xs',
                    generatorRunning
                        ? 'border-emerald-300 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-100 dark:hover:bg-emerald-900/40'
                        : 'border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/40',
                ]"
            >
                <Loader2 v-if="generatorBusy" class="size-2.5 animate-spin sm:size-3" />
                <span
                    v-else
                    :class="['size-1.5 rounded-full sm:size-2', generatorRunning ? 'bg-emerald-500 animate-pulse' : 'bg-red-500']"
                />
                Gen {{ generatorRunning ? 'ON' : 'OFF' }}
                <span v-if="generatorRunning" class="hidden tabular-nums opacity-70 sm:inline">· {{ generatorReady }}</span>
            </button>
        </div>
    </header>
</template>
