<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, Smartphone, ShieldCheck, Cpu, Globe, Mail, Hash, Layers, Zap, Star, ChevronRight, Languages } from 'lucide-vue-next';
import { renderSVG } from 'uqr';
import { computed } from 'vue';
import { usePublicLang } from '@/composables/usePublicLang';

interface AppInfo {
    title: string;
    tagline: string | null;
    description: string | null;
    package_name: string | null;
    developer_name: string | null;
    developer_email: string | null;
    developer_website: string | null;
    logo_url: string | null;
    features: string[];
}

interface Release {
    version_name: string;
    version_code: number | null;
    file_name: string;
    file_size: number;
    checksum_sha256: string;
    changelog: string | null;
    min_android: string | null;
    download_count: number;
    released_at: string | null;
}

interface Screenshot {
    id: number;
    url: string;
    caption: string | null;
}

const props = defineProps<{
    app: AppInfo;
    release: Release | null;
    screenshots: Screenshot[];
    downloadUrl: string;
}>();

const qrSvg = computed(() =>
    renderSVG(props.downloadUrl, {
        ecc: 'M',
        border: 1,
        blackColor: '#111111',
        whiteColor: '#ffffff',
    }),
);

const fileSizeMb = computed(() => (props.release ? (props.release.file_size / 1024 / 1024).toFixed(1) + ' MB' : '—'));

const releasedDate = computed(() => {
    if (!props.release?.released_at) return '—';
    return new Date(props.release.released_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
});

const changelogLines = computed(() => (props.release?.changelog ?? '').split('\n').filter((l) => l.trim().length > 0));

const shortChecksum = computed(() => {
    const c = props.release?.checksum_sha256 ?? '';
    return c ? c.slice(0, 12) + '…' + c.slice(-12) : '—';
});

const featureIcons = [ShieldCheck, Cpu, Smartphone, Zap, Layers, Globe];

const { isBangla, setLang, t } = usePublicLang();
</script>

<template>
    <Head :title="`${app.title} — Download`" />

    <div class="min-h-screen bg-white font-sans text-gray-900 antialiased" :class="{ 'font-bangla': isBangla }">

        <!-- Header -->
        <header class="border-b border-gray-100 bg-white/80 backdrop-blur-sm sticky top-0 z-20">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-3">
                    <img v-if="app.logo_url" :src="app.logo_url" :alt="app.title" class="h-8 w-8 rounded-lg object-cover shadow-sm" />
                    <div v-else class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-900">
                        <Smartphone class="h-4 w-4 text-white" />
                    </div>
                    <span class="font-semibold text-gray-900">{{ app.title }}</span>
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
                    <a v-if="release" :href="downloadUrl" class="hidden items-center gap-2 rounded-full bg-gray-900 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-black transition-colors sm:inline-flex">
                        <Download class="h-3.5 w-3.5" />
                        {{ t({ bn: 'নামাও', en: 'Download' }) }}
                    </a>
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="mx-auto max-w-5xl px-6 pt-16 pb-12">
            <div class="flex flex-col items-start gap-8 lg:flex-row lg:items-center lg:gap-16">
                <!-- App identity -->
                <div class="flex-1">
                    <div class="mb-4 inline-flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">
                        <Smartphone class="h-3.5 w-3.5" />
                        {{ t({ bn: 'অ্যান্ড্রয়েড অ্যাপ', en: 'Android App' }) }}
                        <span v-if="release">· v{{ release.version_name }}</span>
                    </div>

                    <div class="flex items-center gap-5 mb-5">
                        <img v-if="app.logo_url" :src="app.logo_url" :alt="app.title" class="h-20 w-20 rounded-2xl object-cover shadow-lg ring-1 ring-gray-100 sm:h-24 sm:w-24 shrink-0" />
                        <h1 class="text-4xl font-bold tracking-tight text-gray-900 sm:text-5xl">{{ app.title }}</h1>
                    </div>

                    <p v-if="app.tagline" class="text-lg font-medium text-gray-600 mb-3">{{ app.tagline }}</p>
                    <p v-if="app.description" class="text-base leading-relaxed text-gray-500 max-w-lg">{{ app.description }}</p>

                    <div class="mt-8 flex flex-wrap items-center gap-4">
                        <a v-if="release" :href="downloadUrl" class="inline-flex items-center gap-2.5 rounded-xl bg-gray-900 px-7 py-3.5 text-sm font-semibold text-white shadow-md hover:bg-black active:scale-[0.98] transition-all">
                            <Download class="h-4 w-4" />
                            {{ t({ bn: 'APK নামায় ফেলো', en: 'Download APK' }) }}
                            <span v-if="release" class="text-gray-400 font-normal">{{ fileSizeMb }}</span>
                        </a>
                        <div v-if="release" class="text-xs text-gray-400 leading-relaxed">
                            <div class="flex items-center gap-1.5"><Hash class="h-3 w-3" /> SHA-256 {{ shortChecksum }}</div>
                            <div class="flex items-center gap-1.5"><Download class="h-3 w-3" /> {{ release.download_count.toLocaleString() }} {{ t({ bn: 'ডাউনলোড', en: 'downloads' }) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Hero phone mockup -->
                <div class="hidden lg:flex shrink-0 items-center justify-center">
                    <div class="relative">
                        <!-- Subtle glow behind phone -->
                        <div class="absolute inset-0 scale-90 rounded-full bg-gray-200 blur-3xl opacity-60"></div>
                        <!-- Phone frame -->
                        <div class="relative rounded-[3rem] border-[8px] border-gray-800 bg-gray-800 shadow-2xl ring-1 ring-gray-700">
                            <!-- Notch -->
                            <div class="absolute top-0 left-1/2 -translate-x-1/2 h-5 w-28 rounded-b-xl bg-gray-800 z-10"></div>
                            <!-- Side buttons -->
                            <div class="absolute -left-[10px] top-20 h-8 w-[3px] rounded-full bg-gray-600"></div>
                            <div class="absolute -left-[10px] top-32 h-14 w-[3px] rounded-full bg-gray-600"></div>
                            <div class="absolute -left-[10px] top-48 h-14 w-[3px] rounded-full bg-gray-600"></div>
                            <div class="absolute -right-[10px] top-28 h-20 w-[3px] rounded-full bg-gray-600"></div>
                            <div class="overflow-hidden rounded-[2.4rem] bg-[#0f172a]">
                                <!-- SMS Forwarding animation screen -->
                                <div class="flex h-[31.25rem] w-[14.5rem] flex-col bg-[#0f172a] select-none">
                                    <!-- Status bar -->
                                    <div class="flex items-center justify-between px-5 pt-2 pb-1 text-[9px] text-slate-500">
                                        <span class="font-medium">9:41</span>
                                        <div class="flex items-center gap-0.5">
                                            <svg class="h-1.5 w-2" viewBox="0 0 12 10" fill="currentColor"><rect x="0" y="5" width="2" height="5" rx="0.5" opacity="0.4"/><rect x="3" y="3" width="2" height="7" rx="0.5" opacity="0.6"/><rect x="6" y="1" width="2" height="9" rx="0.5" opacity="0.8"/><rect x="9" y="0" width="2" height="10" rx="0.5"/></svg>
                                            <svg class="h-1.5 w-2" viewBox="0 0 12 10" fill="currentColor"><path d="M6 2.5C7.8 2.5 9.4 3.3 10.5 4.5L12 3C10.5 1.1 8.4 0 6 0S1.5 1.1 0 3l1.5 1.5C2.6 3.3 4.2 2.5 6 2.5z" opacity="0.5"/><path d="M6 5.5c1 0 1.9.4 2.5 1L10 5C9 3.8 7.6 3 6 3S3 3.8 2 5l1.5 1.5c.6-.6 1.5-1 2.5-1z" opacity="0.75"/><circle cx="6" cy="8.5" r="1.5"/></svg>
                                            <svg class="h-1.5 w-3.5" viewBox="0 0 20 10" fill="none"><rect x="0.5" y="0.5" width="17" height="9" rx="2.5" stroke="currentColor" stroke-opacity="0.5"/><rect x="1.5" y="1.5" width="13" height="7" rx="1.5" fill="currentColor" fill-opacity="0.9"/><path d="M19 3.5v3a1.5 1.5 0 000-3z" fill="currentColor" fill-opacity="0.4"/></svg>
                                        </div>
                                    </div>

                                    <!-- App header -->
                                    <div class="flex items-center justify-between px-4 py-2.5 border-b border-slate-800">
                                        <div class="flex items-center gap-2">
                                            <div class="h-6 w-6 rounded-lg bg-slate-800 flex items-center justify-center">
                                                <Zap class="h-3.5 w-3.5 text-emerald-400" />
                                            </div>
                                            <span class="text-[11px] font-semibold text-white tracking-wide">SMS Forwarder</span>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                            <span class="text-[8px] text-emerald-400 font-medium">ON</span>
                                        </div>
                                    </div>

                                    <!-- Animation stage -->
                                    <div class="flex-1 flex flex-col items-center justify-center px-4 gap-0 relative overflow-hidden">

                                        <!-- Incoming SMS label -->
                                        <div class="sms-label mb-2 self-start">
                                            <span class="text-[8px] font-medium text-slate-500 uppercase tracking-wider">Incoming SMS</span>
                                        </div>

                                        <!-- SMS bubble -->
                                        <div class="sms-bubble w-full rounded-xl bg-slate-800 border border-slate-700 p-3 shadow-lg self-start">
                                            <div class="flex items-center gap-1.5 mb-1.5">
                                                <div class="h-4 w-4 rounded-full bg-slate-600 flex items-center justify-center">
                                                    <Smartphone class="h-2.5 w-2.5 text-slate-300" />
                                                </div>
                                                <span class="text-[8px] text-slate-400 font-mono">+880 17XX XXXXXX</span>
                                            </div>
                                            <p class="text-[10px] text-slate-200 leading-relaxed">Your OTP is <span class="font-bold text-emerald-300">4 8 2 1</span></p>
                                            <div class="mt-1.5 text-[7px] text-slate-600">09:41 AM · SMS</div>
                                        </div>

                                        <!-- Forwarding arrows -->
                                        <div class="forward-track flex flex-col items-center my-3 gap-1">
                                            <div class="fwd-dot fwd-dot-1"></div>
                                            <div class="fwd-dot fwd-dot-2"></div>
                                            <div class="fwd-dot fwd-dot-3"></div>
                                            <div class="fwd-label text-[8px] font-medium text-slate-500 mt-1">Forwarding…</div>
                                        </div>

                                        <!-- Server / delivered -->
                                        <div class="delivered-badge flex items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 self-center">
                                            <Globe class="h-3.5 w-3.5 text-emerald-400 shrink-0" />
                                            <div>
                                                <div class="text-[9px] font-semibold text-emerald-300">Delivered to server</div>
                                                <div class="text-[7px] text-emerald-600">ipms.senda.fit · just now</div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Footer log -->
                                    <div class="border-t border-slate-800 px-4 py-3">
                                        <div class="flex items-center justify-between">
                                            <span class="text-[8px] text-slate-600">Today</span>
                                            <span class="forwarded-count text-[8px] font-semibold text-emerald-500">3 forwarded</span>
                                        </div>
                                        <div class="mt-1.5 space-y-0.5">
                                            <div class="log-row flex items-center gap-1.5">
                                                <span class="h-1 w-1 rounded-full bg-emerald-500"></span>
                                                <span class="text-[7px] text-slate-600 font-mono truncate">+880 17XX · OTP · 09:41</span>
                                            </div>
                                            <div class="log-row log-row-2 flex items-center gap-1.5">
                                                <span class="h-1 w-1 rounded-full bg-emerald-500"></span>
                                                <span class="text-[7px] text-slate-600 font-mono truncate">+880 19XX · OTP · 09:38</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats strip below hero -->
            <div v-if="release" class="mt-10 grid grid-cols-2 gap-4 rounded-2xl border border-gray-100 bg-gray-50 p-6 shadow-sm sm:grid-cols-4 sm:gap-0 sm:divide-x sm:divide-gray-200">
                <div class="px-4">
                    <div class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">{{ t({ bn: 'ভার্সন', en: 'Version' }) }}</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ release.version_name }}</div>
                </div>
                <div class="px-4">
                    <div class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">{{ t({ bn: 'সাইজ', en: 'Size' }) }}</div>
                    <div class="mt-1 text-xl font-bold text-gray-900">{{ fileSizeMb }}</div>
                </div>
                <div class="px-4">
                    <div class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">{{ t({ bn: 'যা লাগবে', en: 'Requires' }) }}</div>
                    <div class="mt-1 text-sm font-semibold text-gray-700">Android {{ release.min_android ?? '8.0' }}+</div>
                </div>
                <div class="px-4">
                    <div class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">{{ t({ bn: 'আপডেট হইছে', en: 'Updated' }) }}</div>
                    <div class="mt-1 text-sm font-semibold text-gray-700">{{ releasedDate }}</div>
                </div>
            </div>
        </section>

        <!-- Divider -->
        <div class="border-t border-gray-100"></div>

        <!-- Features -->
        <section v-if="app.features.length > 0" class="mx-auto max-w-5xl px-6 py-16">
            <div class="mb-10 text-center">
                <h2 class="text-2xl font-bold text-gray-900">{{ t({ bn: 'ভিতরে কী কী আছে', en: "What's inside" }) }}</h2>
                <p class="mt-2 text-gray-500">{{ t({ bn: 'যা যা লাগে সব আছে, ফালতু একটা জিনিসও নাই।', en: "Everything you need, nothing you don't." }) }}</p>
            </div>
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="(feature, i) in app.features" :key="i" class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm hover:shadow-md hover:border-gray-300 transition-all">
                    <div class="mb-4 flex h-10 w-10 items-center justify-center rounded-xl bg-gray-100 text-gray-700">
                        <component :is="featureIcons[i % featureIcons.length]" class="h-5 w-5" />
                    </div>
                    <p class="text-sm leading-relaxed text-gray-700 font-medium">{{ feature }}</p>
                </div>
            </div>
        </section>

        <!-- Screenshots — App Store style -->
        <section v-if="screenshots.length > 0" class="bg-gray-50 py-16">
            <div class="mx-auto max-w-5xl px-6">
                <div class="mb-10 text-center">
                    <h2 class="text-2xl font-bold text-gray-900">{{ t({ bn: 'স্ক্রিনশট', en: 'Screenshots' }) }}</h2>
                    <p class="mt-2 text-gray-500">{{ t({ bn: 'অ্যাপটা কাম করতিসে ক্যামনে, নিজ চোখে দেইখে লও।', en: 'See the app in action.' }) }}</p>
                </div>
            </div>
            <!-- Full-width horizontal scroll gallery -->
            <div class="flex snap-x snap-mandatory gap-4 overflow-x-auto px-6 pb-6 sm:gap-6" style="scrollbar-width: none; -ms-overflow-style: none;">
                <div v-for="shot in screenshots" :key="shot.id" class="shrink-0 snap-start flex flex-col items-center">
                    <!-- Phone frame mockup -->
                    <div class="relative rounded-[2.5rem] border-[7px] border-gray-800 bg-gray-800 shadow-2xl ring-1 ring-gray-700">
                        <!-- Notch -->
                        <div class="absolute top-0 left-1/2 -translate-x-1/2 h-5 w-24 rounded-b-xl bg-gray-800 z-10"></div>
                        <div class="overflow-hidden rounded-[2rem] bg-black">
                            <img
                                :src="shot.url"
                                :alt="shot.caption ?? 'Screenshot'"
                                class="block h-[28.75rem] w-auto max-w-[13.75rem] object-cover"
                                loading="lazy"
                            />
                        </div>
                    </div>
                    <p v-if="shot.caption" class="mt-4 text-center text-xs text-gray-500 max-w-[12.5rem]">{{ shot.caption }}</p>
                </div>
            </div>
        </section>

        <!-- Changelog -->
        <section v-if="changelogLines.length > 0" class="mx-auto max-w-5xl px-6 py-16">
            <div class="mb-8">
                <h2 class="text-2xl font-bold text-gray-900">{{ t({ bn: `v${release?.version_name} ভার্সনে নতুন কী আইলো`, en: `What's new in v${release?.version_name}` }) }}</h2>
                <p class="mt-2 text-gray-500">{{ t({ bn: `ছাড়া হইছে ${releasedDate}`, en: `Released ${releasedDate}` }) }}</p>
            </div>
            <div class="rounded-2xl border border-gray-100 bg-white shadow-sm divide-y divide-gray-50">
                <div v-for="(line, i) in changelogLines" :key="i" class="flex items-start gap-3 px-6 py-3.5">
                    <ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />
                    <span class="text-sm text-gray-700">{{ line }}</span>
                </div>
            </div>
        </section>

        <!-- QR Code CTA — bottom -->
        <section class="bg-gradient-to-b from-gray-50 to-white py-20 border-t border-gray-100">
            <div class="mx-auto max-w-5xl px-6">
                <div class="flex flex-col items-center gap-10 lg:flex-row lg:items-center lg:justify-center lg:gap-20">
                    <!-- QR code -->
                    <div class="flex flex-col items-center gap-4">
                        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-lg">
                            <div class="h-48 w-48 [&>svg]:h-full [&>svg]:w-full" v-html="qrSvg"></div>
                        </div>
                        <p class="text-center text-xs text-gray-400">{{ t({ bn: 'সাথে সাথে নামাতে স্ক্যান মারো', en: 'Scan to download instantly' }) }}</p>
                    </div>

                    <!-- CTA text -->
                    <div class="text-center lg:text-left">
                        <h2 class="text-3xl font-bold text-gray-900 sm:text-4xl">{{ t({ bn: 'এহনই অ্যাপটা নিয়ে নাও', en: 'Get the app now' }) }}</h2>
                        <p class="mt-3 text-lg text-gray-500 max-w-md">{{ t({ bn: 'ফোনের ক্যামেরাটা QR কোডের দিকে ধরো — নাইলে নিচের বাটনে চাপ দিয়ে সোজা APK নামায় ফেলো।', en: 'Point your phone camera at the QR code — or tap the button below to download the APK directly.' }) }}</p>
                        <a v-if="release" :href="downloadUrl" class="mt-6 inline-flex items-center gap-2.5 rounded-xl bg-gray-900 px-8 py-4 text-base font-semibold text-white shadow-md hover:bg-black active:scale-[0.98] transition-all">
                            <Download class="h-5 w-5" />
                            {{ t({ bn: 'APK নামায় ফেলো', en: 'Download APK' }) }}
                            <span v-if="release" class="text-gray-400 font-normal text-sm">{{ fileSizeMb }}</span>
                        </a>
                        <p v-if="release" class="mt-4 text-xs text-gray-400">Android {{ release.min_android ?? '8.0' }}+ · {{ t({ bn: 'একদম ফ্রি · অ্যাকাউন্ট-ট্যাকাউন্ট লাগে না', en: 'Free · No account required' }) }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="border-t border-gray-100 bg-white">
            <div class="mx-auto flex max-w-5xl flex-col items-start justify-between gap-6 px-6 py-10 sm:flex-row sm:items-center">
                <div>
                    <div class="text-xs font-medium uppercase tracking-wider text-gray-400">{{ t({ bn: 'বানাইছে', en: 'Developed by' }) }}</div>
                    <div class="mt-1 text-base font-semibold text-gray-900">{{ app.developer_name ?? '—' }}</div>
                    <div v-if="app.package_name" class="mt-0.5 text-xs text-gray-400">{{ app.package_name }}</div>
                </div>
                <div class="flex flex-col gap-2 text-sm text-gray-400">
                    <a v-if="app.developer_email" :href="`mailto:${app.developer_email}`" class="flex items-center gap-2 hover:text-gray-900 transition-colors">
                        <Mail class="h-4 w-4" /> {{ app.developer_email }}
                    </a>
                    <a v-if="app.developer_website" :href="app.developer_website" target="_blank" rel="noopener" class="flex items-center gap-2 hover:text-gray-900 transition-colors">
                        <Globe class="h-4 w-4" /> {{ app.developer_website }}
                    </a>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/* Hide scrollbar for screenshot gallery */
.overflow-x-auto::-webkit-scrollbar {
    display: none;
}

/* ── SMS Forwarding Animation ── */
/* Total cycle: 5s. Timeline:
   0–20%   SMS + label hidden (idle)
   20–35%  SMS slides up into view
   35–60%  dots travel upward (forwarding)
   60–80%  delivered badge fades in
   80–95%  everything holds
   95–100% fade out, reset
*/

.sms-label {
    animation: sms-appear 5s ease-in-out infinite;
}

@keyframes sms-appear {
    0%, 18%         { opacity: 0; }
    28%, 78%        { opacity: 1; }
    90%, 100%       { opacity: 0; }
}

.sms-bubble {
    animation: sms-slide 5s ease-out infinite;
}

@keyframes sms-slide {
    0%, 18%         { opacity: 0; transform: translateY(18px); }
    30%, 78%        { opacity: 1; transform: translateY(0); }
    90%, 100%       { opacity: 0; transform: translateY(-6px); }
}

/* Forwarding dots — travel upward one after another */
.fwd-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #10b981;
}

.fwd-dot-1 { animation: dot-travel 5s ease-in-out infinite 0s; }
.fwd-dot-2 { animation: dot-travel 5s ease-in-out infinite 0.15s; }
.fwd-dot-3 { animation: dot-travel 5s ease-in-out infinite 0.3s; }

@keyframes dot-travel {
    0%, 33%         { opacity: 0; transform: translateY(0); }
    38%             { opacity: 0.8; transform: translateY(0); }
    58%             { opacity: 0; transform: translateY(-18px); }
    100%            { opacity: 0; transform: translateY(-18px); }
}

.fwd-label {
    animation: fwd-label-blink 5s ease-in-out infinite;
}

@keyframes fwd-label-blink {
    0%, 33%         { opacity: 0; }
    40%, 57%        { opacity: 1; }
    63%, 100%       { opacity: 0; }
}

.delivered-badge {
    animation: delivered-pop 5s ease-out infinite;
}

@keyframes delivered-pop {
    0%, 57%         { opacity: 0; transform: scale(0.85); }
    67%, 80%        { opacity: 1; transform: scale(1); }
    90%, 100%       { opacity: 0; transform: scale(0.95); }
}

.forwarded-count {
    animation: count-blink 5s ease-in-out infinite;
}

@keyframes count-blink {
    0%, 60%         { opacity: 0.4; }
    70%, 85%        { opacity: 1; }
    95%, 100%       { opacity: 0.4; }
}
</style>
