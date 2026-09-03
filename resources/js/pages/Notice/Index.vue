<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { Loader2, Megaphone, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useToast } from 'vue-toastification';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import Switch from '@/components/ui/Switch.vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const MAX_LENGTH = 2000;

type NoticeRow = {
    /** Null while the row has never been saved. */
    id: number | null;
    text: string;
    enabled: boolean;
    saving: boolean;
    deleting: boolean;
};

const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Notice', href: '/notice' },
];

const notices = ref<NoticeRow[]>([]);
const loading = ref(true);

/** Preview mirrors the header: every enabled notice on one scrolling track. */
const previewText = computed(() =>
    notices.value
        .filter((notice) => notice.enabled && notice.text.trim() !== '')
        .map((notice) => notice.text.trim())
        .join('   •   '),
);

function blankRow(): NoticeRow {
    return { id: null, text: '', enabled: true, saving: false, deleting: false };
}

async function fetchNotices() {
    loading.value = true;
    try {
        const res = await axios.get('/api/notices');
        const rows: { id: number; text: string; enabled: boolean }[] = res.data?.data ?? [];
        notices.value = rows.map((row) => ({ ...row, saving: false, deleting: false }));
    } catch {
        toast.error('Failed to load the notices.');
    } finally {
        loading.value = false;
    }
}

function addNotice() {
    notices.value.push(blankRow());
}

/** Pushes the shared prop back down so the header marquee updates without a full reload. */
function refreshBanner() {
    router.reload({ only: ['notices'] });
}

function errorMessage(error: any, fallback: string): string {
    const errors = error?.response?.data?.errors;
    if (errors) {
        return (Object.values(errors).flat() as string[])[0] ?? fallback;
    }

    return error?.response?.data?.message ?? fallback;
}

async function save(notice: NoticeRow) {
    if (notice.saving) return;

    const text = notice.text.trim();
    if (text === '') {
        toast.error('The notice text cannot be empty.');

        return;
    }
    if (text.length > MAX_LENGTH) {
        toast.error(`The notice must be ${MAX_LENGTH} characters or fewer.`);

        return;
    }

    notice.saving = true;
    try {
        const payload = { text, enabled: notice.enabled };
        const res = notice.id === null
            ? await axios.post('/api/notices', payload)
            : await axios.put(`/api/notices/${notice.id}`, payload);

        notice.id = res.data?.id ?? notice.id;
        notice.text = res.data?.text ?? text;
        notice.enabled = res.data?.enabled === true;
        toast.success('Notice saved.');
        refreshBanner();
    } catch (error: any) {
        toast.error(errorMessage(error, 'Failed to save the notice.'));
    } finally {
        notice.saving = false;
    }
}

/** Flips the switch and persists it in one click — an unsaved row just holds the state. */
async function toggle(notice: NoticeRow, value: boolean) {
    notice.enabled = value;

    if (notice.id === null || notice.text.trim() === '') {
        return;
    }

    await save(notice);
}

async function remove(notice: NoticeRow, index: number) {
    if (notice.deleting) return;

    if (notice.id === null) {
        notices.value.splice(index, 1);

        return;
    }

    if (!window.confirm('Delete this notice?')) return;

    notice.deleting = true;
    try {
        await axios.delete(`/api/notices/${notice.id}`);
        notices.value.splice(index, 1);
        toast.success('Notice deleted.');
        refreshBanner();
    } catch (error: any) {
        toast.error(errorMessage(error, 'Failed to delete the notice.'));
    } finally {
        notice.deleting = false;
    }
}

onMounted(() => {
    fetchNotices();
});
</script>

<template>
    <Head title="Notice" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-amber-400 to-amber-600 shadow-sm shadow-amber-500/30"
                    >
                        <Megaphone class="h-5 w-5 text-white" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Notice</h1>
                        <p class="text-muted-foreground text-sm">
                            Every enabled notice scrolls in the header for all signed-in users.
                        </p>
                    </div>
                </div>
                <Button class="bg-emerald-600 text-white hover:bg-emerald-700" @click="addNotice">
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    Add notice
                </Button>
            </div>

            <div class="space-y-1.5">
                <Label class="text-[11px] font-semibold">Header preview</Label>
                <div class="overflow-hidden rounded-md border border-zinc-200/70 dark:border-zinc-700/70">
                    <div v-if="previewText" class="flex items-center gap-2 bg-red-50/80 px-3 py-1.5 dark:bg-red-950/30">
                        <Megaphone class="h-3.5 w-3.5 shrink-0 text-red-600 dark:text-red-400" />
                        <span class="font-bangla min-w-0 flex-1 truncate text-[16px] leading-relaxed text-red-600 dark:text-red-400">
                            {{ previewText }}
                        </span>
                    </div>
                    <p v-else class="px-3 py-2 text-[10px] text-zinc-400">
                        Nothing will be shown — no notice is enabled with text.
                    </p>
                </div>
            </div>

            <div v-if="loading" class="text-muted-foreground flex items-center gap-2 p-6 text-sm">
                <Loader2 class="h-4 w-4 animate-spin" />
                Loading…
            </div>

            <div v-else class="space-y-3">
                <p v-if="!notices.length" class="rounded-lg border border-dashed border-zinc-300 p-6 text-center text-xs text-zinc-400 dark:border-zinc-700">
                    No notices yet. Use “Add notice” to write the first one.
                </p>

                <div
                    v-for="(notice, index) in notices"
                    :key="notice.id ?? `new-${index}`"
                    class="space-y-3 rounded-lg border border-zinc-200/60 bg-zinc-50/60 p-3 backdrop-blur-sm dark:border-zinc-700/60 dark:bg-zinc-900/40"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span
                                :class="[
                                    'rounded-full px-2 py-0.5 text-[10px] font-semibold',
                                    notice.enabled
                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                        : 'bg-zinc-200 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
                                ]"
                            >
                                {{ notice.enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                            <span class="text-[10px] text-zinc-400">{{ notice.id === null ? 'Unsaved' : `#${notice.id}` }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <Switch :model-value="notice.enabled" @update:modelValue="(value: boolean) => toggle(notice, value)" />
                            <Button
                                variant="outline"
                                :disabled="notice.deleting"
                                class="border-red-300 text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-950/40"
                                @click="remove(notice, index)"
                            >
                                <Loader2 v-if="notice.deleting" class="h-3.5 w-3.5 animate-spin" />
                                <Trash2 v-else class="h-3.5 w-3.5" />
                            </Button>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <Label :for="`notice-text-${index}`" class="text-[11px] font-semibold">Notice text (Bangla)</Label>
                            <span
                                :class="[
                                    'text-[10px] tabular-nums',
                                    notice.text.length > MAX_LENGTH ? 'font-semibold text-red-600 dark:text-red-400' : 'text-zinc-400',
                                ]"
                            >
                                {{ notice.text.length }} / {{ MAX_LENGTH }}
                            </span>
                        </div>
                        <textarea
                            :id="`notice-text-${index}`"
                            v-model="notice.text"
                            rows="3"
                            dir="auto"
                            placeholder="আপনার নোটিশ এখানে লিখুন…"
                            class="font-bangla w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm leading-relaxed text-zinc-900 placeholder:text-zinc-400 focus:ring-2 focus:ring-amber-500/40 focus:outline-none dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100"
                        />
                    </div>

                    <div class="flex justify-end">
                        <Button :disabled="notice.saving" class="bg-emerald-600 text-white hover:bg-emerald-700" @click="save(notice)">
                            <Loader2 v-if="notice.saving" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                            Save
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
