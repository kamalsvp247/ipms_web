<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, Puzzle, ShieldCheck, Zap, Link2, Hash, ChevronRight, RefreshCw, CheckCircle2, Languages } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { usePublicLang } from '@/composables/usePublicLang';

interface AppInfo {
    name: string;
    version: string | null;
    description: string | null;
    author: string;
    logo_url: string;
    extension_id: string | null;
}

interface FileInfo {
    name: string;
    size: number;
    checksum_sha256: string;
}

const props = defineProps<{
    app: AppInfo;
    file: FileInfo | null;
    downloadUrl: string;
}>();

const { isBangla, setLang, t } = usePublicLang();

const fileSizeKb = computed(() => (props.file ? (props.file.size / 1024).toFixed(1) + ' KB' : '—'));

const shortChecksum = computed(() => {
    const c = props.file?.checksum_sha256 ?? '';
    return c ? c.slice(0, 12) + '…' + c.slice(-12) : '—';
});

// ── Install-state detection ────────────────────────────────────────────────
// The extension declares externally_connectable for this origin, so the page can
// ping it to learn whether it is installed and which version, then adapt the CTA.
type InstallState = 'unknown' | 'not_installed' | 'up_to_date' | 'update_available';
const installState = ref<InstallState>('unknown');
const installedVersion = ref<string | null>(null);

const compareVersions = (a: string, b: string): number => {
    const pa = a.split('.').map((n) => parseInt(n, 10) || 0);
    const pb = b.split('.').map((n) => parseInt(n, 10) || 0);
    const len = Math.max(pa.length, pb.length);
    for (let i = 0; i < len; i++) {
        const diff = (pa[i] ?? 0) - (pb[i] ?? 0);
        if (diff !== 0) return diff < 0 ? -1 : 1;
    }
    return 0;
};

const detectExtension = (): void => {
    const id = props.app.extension_id;
    const chromeApi = (window as unknown as { chrome?: any }).chrome;
    if (!id || !chromeApi?.runtime?.sendMessage) {
        installState.value = 'not_installed';
        return;
    }
    try {
        chromeApi.runtime.sendMessage(id, { type: 'DURONTO_PAYMENT_HELPER_PING' }, (resp: { installed?: boolean; version?: string } | undefined) => {
            if (chromeApi.runtime.lastError || !resp?.installed) {
                installState.value = 'not_installed';
                return;
            }
            installedVersion.value = resp.version ?? null;
            if (props.app.version && resp.version && compareVersions(resp.version, props.app.version) < 0) {
                installState.value = 'update_available';
            } else {
                installState.value = 'up_to_date';
            }
        });
    } catch {
        installState.value = 'not_installed';
    }
};

onMounted(detectExtension);

const startDownload = (): void => {
    window.location.href = props.downloadUrl;
};

const features = computed(() => [
    { icon: Link2, title: t({ bn: 'নিজে নিজেই লিংক খপ কইরে ধরে', en: 'Auto-captures the callback' }), text: t({ bn: 'ব্রাউজারে IVAC dg-epay পেমেন্টের রিডাইরেক্ট লিংকে ঢুকলিই সাথে সাথে খপ কইরে ধইরে ফেলে — তোমার কপি-পেস্ট করার কোনো দরকারই নাই গা।', en: 'Detects the IVAC dg-epay payment redirect URL the moment your browser lands on it — no copy-paste.' }) },
    { icon: Zap, title: t({ bn: 'চোখের পলকে পাঠায় দেয়', en: 'Instant hand-off' }), text: t({ bn: 'রিডাইরেক্ট লিংকটা সোজা DURONTO পোর্টালে চালান কইরে দেয়, আর ওই পেমেন্ট লিংক নিজে নিজেই কাম সাইরে ফেলে। তুমি খালি বইসে বইসে দেখো।', en: 'Forwards the redirect URL straight to the DURONTO portal so the matching payment link completes on its own.' }) },
    { icon: ShieldCheck, title: t({ bn: 'খালি নিজের কাম, বাড়তি প্যাচাল নাই', en: 'Scoped & silent' }), text: t({ bn: 'খালি IVAC পেমেন্ট ডোমেইন আর DURONTO পোর্টালেই চলে। পপ-আপের ঝামেলা নাই, অ্যাকাউন্ট লাগে না, আর কোনো কিছুতেই নাক গলায় না।', en: 'Runs only on the IVAC payment domain and the DURONTO portal. No pop-ups, no accounts, nothing else touched.' }) },
]);

const steps = computed(() => [
    t({ bn: 'duronto-payment-helper.zip টা নামায় নিয়ে আনজিপ কইরে ফেলো।', en: 'Download and unzip duronto-payment-helper.zip.' }),
    t({ bn: 'chrome://extensions খোলো (edge://extensions / brave://extensions হইলেও চলবে)।', en: 'Open chrome://extensions (or edge://extensions / brave://extensions).' }),
    t({ bn: 'উপরে ডান কোণার Developer mode টগলটা অন কইরে দাও।', en: 'Enable Developer mode (top-right toggle).' }),
    t({ bn: '"Load unpacked"-এ চাপ দিয়ে আনজিপ করা duronto-payment-helper ফোল্ডারটা দেখায় দাও।', en: 'Click "Load unpacked" and select the unzipped duronto-payment-helper folder.' }),
    t({ bn: 'টুলবারে DURONTO-এর বাজ পড়া আইকনটা দেখা গেলিই — ব্যস, কাম শ্যাষ।', en: 'The DURONTO bolt icon appears in your toolbar — you are done.' }),
]);

const ctaLabel = computed(() => {
    if (installState.value === 'update_available') {
        return t({ bn: `আপডেট মাইরে দাও v${props.app.version}`, en: `Update to v${props.app.version}` });
    }
    if (installState.value === 'up_to_date') {
        return t({ bn: 'আছেই তো ভাই', en: 'Installed' });
    }
    return t({ bn: 'Chrome-এ ঢুকায় দাও', en: 'Add to Chrome' });
});
</script>

<template>
    <Head :title="`${app.name} — ${t({ bn: 'ডাউনলোড', en: 'Download' })}`" />

    <div class="min-h-screen bg-white font-sans text-gray-900 antialiased" :class="{ 'font-bangla': isBangla }">
        <!-- Header -->
        <header class="sticky top-0 z-20 border-b border-gray-100 bg-white/80 backdrop-blur-sm">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-3">
                    <img :src="app.logo_url" :alt="app.name" class="h-8 w-8 rounded-lg object-contain" />
                    <span class="font-semibold text-gray-900">{{ app.name }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <!-- Language toggle -->
                    <div class="flex items-center rounded-full border border-gray-200 p-0.5 text-xs font-semibold">
                        <button
                            class="flex items-center gap-1 rounded-full px-2.5 py-1 transition-colors"
                            :class="isBangla ? 'bg-gray-900 text-white' : 'text-gray-500 hover:text-gray-900'"
                            @click="setLang('bn')"
                        >
                            <Languages class="h-3 w-3" /> বাংলা
                        </button>
                        <button
                            class="rounded-full px-2.5 py-1 transition-colors"
                            :class="!isBangla ? 'bg-gray-900 text-white' : 'text-gray-500 hover:text-gray-900'"
                            @click="setLang('en')"
                        >
                            EN
                        </button>
                    </div>
                    <a v-if="file" :href="downloadUrl" class="hidden items-center gap-2 rounded-full bg-gray-900 px-5 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-black sm:inline-flex">
                        <Download class="h-3.5 w-3.5" />
                        {{ t({ bn: 'ডাউনলোড', en: 'Download' }) }}
                    </a>
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="mx-auto max-w-5xl px-6 pt-16 pb-12">
            <div class="mb-4 inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">
                <Puzzle class="h-3.5 w-3.5" />
                {{ t({ bn: 'ব্রাউজার এক্সটেনশন', en: 'Browser Extension' }) }}
                <span v-if="app.version">· v{{ app.version }}</span>
            </div>

            <div class="mb-5 flex items-center gap-5">
                <img :src="app.logo_url" :alt="app.name" class="h-20 w-20 shrink-0 rounded-2xl object-contain shadow-lg ring-1 ring-gray-100 sm:h-24 sm:w-24" />
                <h1 class="text-4xl font-bold tracking-tight text-gray-900 sm:text-5xl">{{ app.name }}</h1>
            </div>

            <p class="max-w-2xl text-base leading-relaxed text-gray-500">
                {{ t({
                    bn: 'ব্রাউজারে IVAC dg-epay পেমেন্টের রিডাইরেক্ট লিংকটা খপ কইরে ধইরে DURONTO পোর্টালে পাঠায় দেয়, আর পেমেন্ট লিংকগুলা নিজে নিজেই কাম সাইরে ফেলে। তুমি নিশ্চিন্তে বইসে থাকো।',
                    en: app.description ?? '',
                }) }}
            </p>

            <!-- Install-state banner -->
            <div v-if="installState === 'update_available'" class="mt-6 inline-flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-700 ring-1 ring-amber-200">
                <RefreshCw class="h-4 w-4" />
                {{ t({ bn: `v${installedVersion} তো আছেই — কিন্তু নতুন v${app.version} আইসে গেছে গা`, en: `v${installedVersion} installed — v${app.version} available` }) }}
            </div>
            <div v-else-if="installState === 'up_to_date'" class="mt-6 inline-flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
                <CheckCircle2 class="h-4 w-4" />
                {{ t({ bn: `ইনস্টল করা আছে, একদম টাটকা (v${installedVersion})`, en: `Installed and up to date (v${installedVersion})` }) }}
            </div>

            <div class="mt-8 flex flex-wrap items-center gap-4">
                <button
                    v-if="file"
                    class="inline-flex items-center gap-2.5 rounded-xl px-7 py-3.5 text-sm font-semibold shadow-md transition-all active:scale-[0.98]"
                    :class="installState === 'up_to_date'
                        ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                        : installState === 'update_available'
                            ? 'bg-amber-500 text-white hover:bg-amber-600'
                            : 'bg-gray-900 text-white hover:bg-black'"
                    @click="startDownload"
                >
                    <component :is="installState === 'update_available' ? RefreshCw : installState === 'up_to_date' ? CheckCircle2 : Download" class="h-4 w-4" />
                    {{ ctaLabel }}
                    <span class="font-normal opacity-70">{{ fileSizeKb }}</span>
                </button>
                <span v-else class="text-sm text-gray-400">{{ t({ bn: 'বিল্ড এহনো তৈয়ার হয় নাই গা।', en: 'Build not available yet.' }) }}</span>
                <div v-if="file" class="text-xs leading-relaxed text-gray-400">
                    <div class="flex items-center gap-1.5"><Hash class="h-3 w-3" /> SHA-256 {{ shortChecksum }}</div>
                    <div class="flex items-center gap-1.5"><Puzzle class="h-3 w-3" /> Chrome · Edge · Brave (Manifest V3)</div>
                </div>
            </div>

            <p class="mt-4 max-w-2xl text-xs leading-relaxed text-gray-400">
                {{ t({
                    bn: 'খেয়াল রাইখো: এক্সটেনশনটা Developer mode-এ "Load unpacked" দিয়ে ঢোকাতে হয় (নিচের ধাপগুলা দেখো)। আইডি ফিক্সড, তাই আপডেট আসলে নতুন ফোল্ডারটা লোড করলিই পুরানটা নিজে থেকে বদলে যায়।',
                    en: 'Note: the extension is added via "Load unpacked" in Developer mode (see steps below). Because it has a fixed ID, re-loading a newer folder updates it in place.',
                }) }}
            </p>
        </section>

        <!-- Features -->
        <section class="mx-auto max-w-5xl px-6 py-8">
            <div class="grid gap-5 sm:grid-cols-3">
                <div v-for="feature in features" :key="feature.title" class="rounded-2xl border border-gray-100 bg-gray-50/60 p-5">
                    <div class="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-gray-900 text-white">
                        <component :is="feature.icon" class="h-4 w-4" />
                    </div>
                    <h3 class="mb-1 text-sm font-semibold text-gray-900">{{ feature.title }}</h3>
                    <p class="text-sm leading-relaxed text-gray-500">{{ feature.text }}</p>
                </div>
            </div>
        </section>

        <!-- Install steps -->
        <section class="mx-auto max-w-5xl px-6 py-8">
            <h2 class="mb-5 text-lg font-semibold text-gray-900">{{ t({ bn: 'কিরাম কইরে ইনস্টল করবা', en: 'How to install' }) }}</h2>
            <ol class="space-y-3">
                <li v-for="(step, i) in steps" :key="i" class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-900 text-xs font-semibold text-white">{{ i + 1 }}</span>
                    <span class="text-sm leading-relaxed text-gray-600">{{ step }}</span>
                </li>
            </ol>
            <div class="mt-6 flex items-center gap-1.5 text-xs text-gray-400">
                <ChevronRight class="h-3.5 w-3.5" />
                {{ t({ bn: 'IVAC পেমেন্ট করার সময় এক্সটেনশনটা চালু রাইখো — ওইটা চুপচাপ পিছন থেকে কাম কইরে যায়, টের-ই পাবা না।', en: 'Keep the extension enabled while completing IVAC payments — it works silently in the background.' }) }}
            </div>
        </section>

        <!-- Footer -->
        <footer class="mt-8 border-t border-gray-100">
            <div class="mx-auto flex max-w-5xl flex-col items-center justify-between gap-2 px-6 py-8 text-xs text-gray-400 sm:flex-row">
                <span>{{ app.name }}<span v-if="app.version"> · v{{ app.version }}</span></span>
                <span>© {{ new Date().getFullYear() }} {{ app.author }}</span>
            </div>
        </footer>
    </div>
</template>
