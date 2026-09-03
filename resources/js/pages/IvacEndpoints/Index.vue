<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full w-full flex-1 flex-col gap-4 p-6">
            <!-- Header -->
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 shadow-sm shadow-violet-500/30">
                        <Waypoints class="h-6 w-6 text-white" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">IVAC Endpoints</h1>
                        <p class="text-muted-foreground mt-0.5 text-sm">
                            Bundle-extracted API paths &amp; headers delivered to the bot &mdash; a redeploy rotation needs no JAR rebuild
                        </p>
                    </div>
                </div>
                <button
                    @click="load"
                    :disabled="loading"
                    class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                >
                    <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': loading }" /> Refresh
                </button>
            </div>

            <!-- Explainer -->
            <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm dark:border-indigo-900 dark:bg-indigo-950/40">
                <div class="flex items-start gap-3">
                    <Info class="mt-0.5 h-5 w-5 flex-shrink-0 text-indigo-600 dark:text-indigo-400" />
                    <div class="text-indigo-800 dark:text-indigo-200">
                        <p>
                            The <span class="font-semibold">Algorithm Monitor</span> auto-extracts these from the live JS bundle on every analysis and ships them via
                            <span class="font-mono text-xs">/api/config</span>. Edit here only to override manually &mdash; e.g. a value the extractor
                            can&rsquo;t decode (<span class="rounded bg-indigo-100 px-1 text-[11px] dark:bg-indigo-900/60">manual</span> badge). The bot falls back to
                            its compiled-in default for any blank key, so a bad value never bricks a call.
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="loading && !loaded" class="flex items-center justify-center py-16 text-sm text-zinc-400">
                <Loader2 class="mr-2 h-5 w-5 animate-spin" /> Loading endpoints&hellip;
            </div>

            <template v-else-if="loaded">
                <!-- Paths -->
                <section class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 p-4 dark:border-zinc-800">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Endpoint Paths</h2>
                    </div>
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <div v-for="key in pathKeys" :key="key" class="p-4">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ meta[key].label }}</span>
                                <span :class="badgeClass(meta[key].sync)">{{ meta[key].sync }}</span>
                                <span v-if="isTemplate(key)" class="rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-black">template</span>
                                <span
                                    v-if="isModified(key)"
                                    class="ml-auto inline-flex items-center gap-1 text-[11px] text-amber-600 dark:text-amber-400"
                                    title="Differs from the compiled-in default"
                                >
                                    <PencilLine class="h-3 w-3" /> overridden
                                </span>
                            </div>
                            <p v-if="meta[key].note" class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ meta[key].note }}</p>
                            <div class="mt-2 flex items-center gap-2">
                                <input
                                    v-model="form[key]"
                                    type="text"
                                    spellcheck="false"
                                    :class="[
                                        'w-full rounded-md border bg-zinc-50 px-3 py-1.5 font-mono text-sm text-zinc-800 outline-none focus:ring-2 dark:bg-zinc-800 dark:text-zinc-200',
                                        fieldError(key)
                                            ? 'border-red-400 focus:ring-red-500/30 dark:border-red-700'
                                            : 'border-zinc-200 focus:ring-indigo-500/30 dark:border-zinc-700',
                                    ]"
                                />
                                <button
                                    v-if="form[key] !== defaults[key]"
                                    @click="form[key] = defaults[key]"
                                    class="inline-flex flex-shrink-0 items-center gap-1 rounded-md border border-zinc-200 px-2 py-1.5 text-xs text-zinc-500 transition-colors hover:bg-zinc-50 hover:text-zinc-700 dark:border-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                                    title="Reset this field to its default"
                                >
                                    <Undo2 class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <p v-if="fieldError(key)" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ fieldError(key) }}</p>
                            <div v-if="isTemplate(key)" class="mt-1.5 text-[11px] text-zinc-400 dark:text-zinc-500">
                                Resolves to
                                <span class="font-mono text-zinc-500 dark:text-zinc-400">{{ resolvedTemplate(key) }}</span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Request constants (substituted into the templated paths above) -->
                <section class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 p-4 dark:border-zinc-800">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Request Constants</h2>
                    </div>
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <div v-for="key in constantKeys" :key="key" class="p-4">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ constantsMeta[key].label }}</span>
                                <span :class="badgeClass('auto')">auto</span>
                                <span class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] text-black font-semibold">
                                    &#123;{{ key }}&#125;
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ constantsMeta[key].note }}</p>
                            <div class="mt-2 flex items-center gap-2">
                                <input
                                    v-model="constantForm[key]"
                                    type="text"
                                    spellcheck="false"
                                    :placeholder="constantsMeta[key].placeholder"
                                    :class="[
                                        'w-full rounded-md border bg-zinc-50 px-3 py-1.5 font-mono text-sm text-zinc-800 outline-none focus:ring-2 dark:bg-zinc-800 dark:text-zinc-200',
                                        constantError(key)
                                            ? 'border-red-400 focus:ring-red-500/30 dark:border-red-700'
                                            : 'border-zinc-200 focus:ring-indigo-500/30 dark:border-zinc-700',
                                    ]"
                                />
                            </div>
                            <p v-if="constantError(key)" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ constantError(key) }}</p>
                        </div>
                    </div>
                </section>

                <!-- Headers -->
                <section class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 p-4 dark:border-zinc-800">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Request Headers</h2>
                    </div>
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <div v-for="key in headerKeys" :key="key" class="p-4">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ meta[key].label }}</span>
                                <span class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] text-black font-semibold">{{ meta[key].header }}</span>
                                <span :class="badgeClass(meta[key].sync)">{{ meta[key].sync }}</span>
                                <span
                                    v-if="isModified(key)"
                                    class="ml-auto inline-flex items-center gap-1 text-[11px] text-amber-600 dark:text-amber-400"
                                    title="Differs from the compiled-in default"
                                >
                                    <PencilLine class="h-3 w-3" /> overridden
                                </span>
                            </div>
                            <p v-if="meta[key].note" class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ meta[key].note }}</p>
                            <div class="mt-2 flex items-center gap-2">
                                <input
                                    v-model="form[key]"
                                    type="text"
                                    spellcheck="false"
                                    :class="[
                                        'w-full rounded-md border bg-zinc-50 px-3 py-1.5 font-mono text-sm text-zinc-800 outline-none focus:ring-2 dark:bg-zinc-800 dark:text-zinc-200',
                                        fieldError(key)
                                            ? 'border-red-400 focus:ring-red-500/30 dark:border-red-700'
                                            : 'border-zinc-200 focus:ring-indigo-500/30 dark:border-zinc-700',
                                    ]"
                                />
                                <button
                                    v-if="form[key] !== defaults[key]"
                                    @click="form[key] = defaults[key]"
                                    class="inline-flex flex-shrink-0 items-center gap-1 rounded-md border border-zinc-200 px-2 py-1.5 text-xs text-zinc-500 transition-colors hover:bg-zinc-50 hover:text-zinc-700 dark:border-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                                    title="Reset this field to its default"
                                >
                                    <Undo2 class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <p v-if="fieldError(key)" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ fieldError(key) }}</p>
                        </div>
                    </div>
                </section>

                <!-- Action bar -->
                <div class="sticky bottom-0 z-10 -mx-6 mt-auto flex items-center gap-3 border-t border-zinc-200 bg-white/80 px-6 py-3 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/80">
                    <span v-if="dirty" class="inline-flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-400">
                        <PencilLine class="h-3.5 w-3.5" /> Unsaved changes
                    </span>
                    <span v-else class="inline-flex items-center gap-1.5 text-xs text-zinc-400 dark:text-zinc-500">
                        <Check class="h-3.5 w-3.5" /> All changes saved
                    </span>
                    <div class="ml-auto flex items-center gap-2">
                        <button
                            @click="resetToDefaults"
                            :disabled="saving"
                            class="inline-flex items-center gap-1.5 rounded-md border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 transition-colors hover:bg-red-50 disabled:opacity-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
                        >
                            <RotateCcw class="h-4 w-4" /> Reset to Defaults
                        </button>
                        <button
                            @click="save"
                            :disabled="saving || !dirty || hasErrors"
                            class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white transition-colors hover:bg-indigo-700 disabled:opacity-50"
                        >
                            <Loader2 v-if="saving" class="h-4 w-4 animate-spin" />
                            <Save v-else class="h-4 w-4" />
                            Save Changes
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import axios from 'axios';
import { Check, Info, Loader2, PencilLine, RefreshCw, RotateCcw, Save, Undo2, Waypoints } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useToast } from 'vue-toastification';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';

interface EndpointMeta {
    label: string;
    group: 'path' | 'header';
    header?: string;
    sync: 'auto' | 'manual';
    anchor?: string;
    note?: string;
}

/** The rotating UUIDs substituted into the templated paths — own settings columns, edited here too. */
interface ConstantMeta {
    label: string;
    column: string;
    placeholder: string;
    template: string;
    note: string;
}

const toast = useToast();
const breadcrumbs = [{ title: 'IVAC Endpoints', href: '/ivac-endpoints' }];

const loading = ref(false);
const loaded = ref(false);
const saving = ref(false);

const meta = ref<Record<string, EndpointMeta>>({});
const defaults = ref<Record<string, string>>({});
const form = reactive<Record<string, string>>({});
const saved = reactive<Record<string, string>>({});
const constantsMeta = ref<Record<string, ConstantMeta>>({});
const constantForm = reactive<Record<string, string>>({});
const constantSaved = reactive<Record<string, string>>({});

const orderedKeys = computed(() => Object.keys(meta.value));
const pathKeys = computed(() => orderedKeys.value.filter((k) => meta.value[k].group === 'path'));
const headerKeys = computed(() => orderedKeys.value.filter((k) => meta.value[k].group === 'header'));
const constantKeys = computed(() => Object.keys(constantsMeta.value));

const dirty = computed(
    () =>
        orderedKeys.value.some((k) => (form[k] ?? '') !== (saved[k] ?? '')) ||
        constantKeys.value.some((k) => (constantForm[k] ?? '') !== (constantSaved[k] ?? '')),
);
const hasErrors = computed(
    () => orderedKeys.value.some((k) => fieldError(k) !== null) || constantKeys.value.some((k) => constantError(k) !== null),
);

function isTemplate(key: string): boolean {
    return key === 'reserveSlot' || key === 'payment';
}

function isModified(key: string): boolean {
    return (form[key] ?? '') !== (defaults.value[key] ?? '');
}

/** Preview a templated path with the live constant typed into the Request Constants card. */
function resolvedTemplate(key: string): string {
    let value = form[key] ?? '';
    for (const constant of constantKeys.value) {
        if (constantsMeta.value[constant].template !== key) {
            continue;
        }
        const placeholder = `{${constant}}`;
        value = value.replace(placeholder, (constantForm[constant] ?? '').trim() || placeholder);
    }
    return value;
}

function badgeClass(sync: 'auto' | 'manual'): string {
    return sync === 'auto'
        ? 'rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-300'
        : 'rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/60 dark:text-amber-300';
}

/** Client-side well-formedness (mirrors the server): paths start "/" and carry their anchor. */
function fieldError(key: string): string | null {
    const info = meta.value[key];
    if (!info) return null;
    const value = (form[key] ?? '').trim();
    if (value === '') return `${info.label} cannot be empty.`;
    if (info.group === 'path' && !value.startsWith('/')) return `Must start with "/".`;
    if (info.anchor && !value.includes(info.anchor)) return `Must contain "${info.anchor}".`;
    return null;
}

/** A blank constant would build a broken URL ("/slots//reserve-slot"), so block the save. */
function constantError(key: string): string | null {
    const info = constantsMeta.value[key];
    if (!info) return null;
    return (constantForm[key] ?? '').trim() === '' ? `${info.label} cannot be empty.` : null;
}

async function load(): Promise<void> {
    loading.value = true;
    try {
        const res = await axios.get('/api/ivac-endpoints');
        meta.value = res.data.meta ?? {};
        defaults.value = res.data.defaults ?? {};
        constantsMeta.value = res.data.constantsMeta ?? {};
        applyEndpoints(res.data.endpoints ?? {});
        applyConstants(res.data.constants ?? {});
        loaded.value = true;
    } catch {
        toast.error('Failed to load endpoints');
    } finally {
        loading.value = false;
    }
}

function applyEndpoints(endpoints: Record<string, string>): void {
    for (const key of Object.keys(meta.value)) {
        const value = endpoints[key] ?? defaults.value[key] ?? '';
        form[key] = value;
        saved[key] = value;
    }
}

function applyConstants(constants: Record<string, string | null>): void {
    for (const key of Object.keys(constantsMeta.value)) {
        const value = constants[key] ?? '';
        constantForm[key] = value;
        constantSaved[key] = value;
    }
}

async function save(): Promise<void> {
    if (hasErrors.value) {
        toast.error('Fix the highlighted fields first');
        return;
    }
    saving.value = true;
    try {
        const payload: Record<string, string> = {};
        for (const key of Object.keys(meta.value)) {
            payload[key] = (form[key] ?? '').trim();
        }
        const constants: Record<string, string> = {};
        for (const key of Object.keys(constantsMeta.value)) {
            constants[key] = (constantForm[key] ?? '').trim();
        }
        const res = await axios.post('/api/ivac-endpoints', { endpoints: payload, constants });
        applyEndpoints(res.data.endpoints ?? {});
        applyConstants(res.data.constants ?? {});
        toast.success('Endpoints saved');
    } catch (e: unknown) {
        if (axios.isAxiosError(e) && e.response?.status === 422) {
            const errs = e.response.data?.errors ?? {};
            const first = Object.values(errs)[0];
            toast.error(Array.isArray(first) ? first[0] : 'Validation failed');
        } else {
            toast.error('Save failed');
        }
    } finally {
        saving.value = false;
    }
}

async function resetToDefaults(): Promise<void> {
    if (!confirm('Reset every path and header back to the compiled-in defaults? This clears all manual overrides. The request constants are left as they are.')) {
        return;
    }
    saving.value = true;
    try {
        const res = await axios.post('/api/ivac-endpoints/reset');
        applyEndpoints(res.data.endpoints ?? {});
        applyConstants(res.data.constants ?? {});
        toast.success('Endpoints reset to defaults');
    } catch {
        toast.error('Reset failed');
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>
