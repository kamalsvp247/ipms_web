<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import {
    Smartphone,
    Save,
    Loader2,
    UploadCloud,
    Trash2,
    CheckCircle2,
    Plus,
    X,
    ExternalLink,
    Image as ImageIcon,
    ArrowUp,
    ArrowDown,
    Pencil,
    Check,
} from 'lucide-vue-next';
import { ref, computed, onMounted } from 'vue';
import { useToast } from 'vue-toastification';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import Switch from '@/components/ui/Switch.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface AppInfo {
    app_title: string;
    tagline: string | null;
    description: string | null;
    package_name: string | null;
    developer_name: string | null;
    developer_email: string | null;
    developer_website: string | null;
    logo_url: string | null;
    features: string[] | null;
    is_published: boolean;
}

interface Release {
    id: number;
    version_name: string;
    version_code: number | null;
    file_name: string;
    file_size: number;
    checksum_sha256: string;
    changelog: string | null;
    min_android: string | null;
    is_active: boolean;
    download_count: number;
    released_at: string | null;
}

interface Screenshot {
    id: number;
    url: string;
    caption: string | null;
    sort_order: number;
}

interface Payload {
    info: AppInfo;
    releases: Release[];
    screenshots: Screenshot[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'APK Distribution', href: '#' },
];

const toast = useToast();

const loading = ref(true);
const releases = ref<Release[]>([]);
const screenshots = ref<Screenshot[]>([]);

// ── App info form ────────────────────────────────────────────────────────────
const form = ref({
    app_title: '',
    tagline: '',
    description: '',
    package_name: '',
    developer_name: '',
    developer_email: '',
    developer_website: '',
    is_published: true,
});
const features = ref<string[]>([]);
const newFeature = ref('');
const logoFile = ref<File | null>(null);
const logoPreview = ref<string | null>(null);
const currentLogoUrl = ref<string | null>(null);
const savingInfo = ref(false);

const applyPayload = (data: Payload) => {
    releases.value = data.releases;
    screenshots.value = data.screenshots;
    currentLogoUrl.value = data.info.logo_url;
    form.value = {
        app_title: data.info.app_title ?? '',
        tagline: data.info.tagline ?? '',
        description: data.info.description ?? '',
        package_name: data.info.package_name ?? '',
        developer_name: data.info.developer_name ?? '',
        developer_email: data.info.developer_email ?? '',
        developer_website: data.info.developer_website ?? '',
        is_published: data.info.is_published,
    };
    features.value = [...(data.info.features ?? [])];
};

const fetchOverview = async () => {
    loading.value = true;
    try {
        const { data } = await axios.get<Payload>('/api/apk/overview');
        applyPayload(data);
    } catch {
        toast.error('Failed to load APK distribution data.');
    } finally {
        loading.value = false;
    }
};

const onLogoSelected = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    logoFile.value = file;
    logoPreview.value = file ? URL.createObjectURL(file) : null;
};

const addFeature = () => {
    const value = newFeature.value.trim();
    if (!value) return;
    features.value.push(value);
    newFeature.value = '';
};

const removeFeature = (index: number) => {
    features.value.splice(index, 1);
};

const saveInfo = async () => {
    savingInfo.value = true;
    try {
        const data = new FormData();
        data.append('app_title', form.value.app_title);
        data.append('tagline', form.value.tagline);
        data.append('description', form.value.description);
        data.append('package_name', form.value.package_name);
        data.append('developer_name', form.value.developer_name);
        data.append('developer_email', form.value.developer_email);
        data.append('developer_website', form.value.developer_website);
        data.append('is_published', form.value.is_published ? '1' : '0');
        features.value.forEach((f) => data.append('features[]', f));
        if (logoFile.value) data.append('logo', logoFile.value);
        const response = await axios.post<Payload>('/api/apk/info', data);
        applyPayload(response.data);
        logoFile.value = null;
        logoPreview.value = null;
        toast.success('App information saved.');
    } catch (error: any) {
        toast.error(error?.response?.data?.message ?? 'Failed to save app information.');
    } finally {
        savingInfo.value = false;
    }
};

// ── Releases ─────────────────────────────────────────────────────────────────
const releaseForm = ref({ version_name: '', version_code: '', min_android: '', changelog: '', activate: true });
const apkFile = ref<File | null>(null);
const uploadingRelease = ref(false);
const uploadProgress = ref(0);
const busyReleaseId = ref<number | null>(null);

const onApkSelected = (event: Event) => {
    apkFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
};

const uploadRelease = async () => {
    if (!apkFile.value || !releaseForm.value.version_name.trim()) {
        toast.error('Select an .apk file and enter a version name.');
        return;
    }
    uploadingRelease.value = true;
    uploadProgress.value = 0;
    try {
        const data = new FormData();
        data.append('apk', apkFile.value);
        data.append('version_name', releaseForm.value.version_name);
        if (releaseForm.value.version_code) data.append('version_code', releaseForm.value.version_code);
        if (releaseForm.value.min_android) data.append('min_android', releaseForm.value.min_android);
        if (releaseForm.value.changelog) data.append('changelog', releaseForm.value.changelog);
        data.append('activate', releaseForm.value.activate ? '1' : '0');
        const response = await axios.post<Payload>('/api/apk/releases', data, {
            onUploadProgress: (e) => {
                uploadProgress.value = e.total ? Math.round((e.loaded / e.total) * 100) : 0;
            },
        });
        applyPayload(response.data);
        releaseForm.value = { version_name: '', version_code: '', min_android: '', changelog: '', activate: true };
        apkFile.value = null;
        toast.success('Release uploaded.');
    } catch (error: any) {
        toast.error(error?.response?.data?.message ?? 'Failed to upload release.');
    } finally {
        uploadingRelease.value = false;
    }
};

const activateRelease = async (release: Release) => {
    busyReleaseId.value = release.id;
    try {
        const { data } = await axios.post<Payload>(`/api/apk/releases/${release.id}/activate`);
        applyPayload(data);
        toast.success(`v${release.version_name} is now the active download.`);
    } catch {
        toast.error('Failed to activate release.');
    } finally {
        busyReleaseId.value = null;
    }
};

const deleteRelease = async (release: Release) => {
    if (!confirm(`Delete release v${release.version_name}? The APK file will be removed permanently.`)) return;
    busyReleaseId.value = release.id;
    try {
        const { data } = await axios.delete<Payload>(`/api/apk/releases/${release.id}`);
        applyPayload(data);
        toast.success('Release deleted.');
    } catch {
        toast.error('Failed to delete release.');
    } finally {
        busyReleaseId.value = null;
    }
};

const formatSize = (bytes: number) => (bytes / 1024 / 1024).toFixed(1) + ' MB';
const formatDate = (value: string | null) => (value ? new Date(value).toLocaleString() : '—');

// ── Screenshots ──────────────────────────────────────────────────────────────
const uploadingScreenshots = ref(false);
const busyScreenshotId = ref<number | null>(null);
const editingCaptionId = ref<number | null>(null);
const captionDraft = ref('');

const onScreenshotsSelected = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const files = input.files;
    if (!files || files.length === 0) return;
    uploadingScreenshots.value = true;
    try {
        const data = new FormData();
        Array.from(files).forEach((file) => data.append('screenshots[]', file));
        const response = await axios.post<Payload>('/api/apk/screenshots', data);
        applyPayload(response.data);
        toast.success('Screenshots uploaded.');
    } catch (error: any) {
        toast.error(error?.response?.data?.message ?? 'Failed to upload screenshots.');
    } finally {
        uploadingScreenshots.value = false;
        input.value = '';
    }
};

const deleteScreenshot = async (shot: Screenshot) => {
    if (!confirm('Delete this screenshot?')) return;
    busyScreenshotId.value = shot.id;
    try {
        const { data } = await axios.delete<Payload>(`/api/apk/screenshots/${shot.id}`);
        applyPayload(data);
    } catch {
        toast.error('Failed to delete screenshot.');
    } finally {
        busyScreenshotId.value = null;
    }
};

const moveScreenshot = async (index: number, direction: -1 | 1) => {
    const target = index + direction;
    if (target < 0 || target >= screenshots.value.length) return;
    const ids = screenshots.value.map((s) => s.id);
    [ids[index], ids[target]] = [ids[target], ids[index]];
    try {
        const { data } = await axios.put<Payload>('/api/apk/screenshots/reorder', { ids });
        applyPayload(data);
    } catch {
        toast.error('Failed to reorder screenshots.');
    }
};

const startCaptionEdit = (shot: Screenshot) => {
    editingCaptionId.value = shot.id;
    captionDraft.value = shot.caption ?? '';
};

const saveCaption = async (shot: Screenshot) => {
    try {
        const { data } = await axios.put<Payload>(`/api/apk/screenshots/${shot.id}`, { caption: captionDraft.value || null });
        applyPayload(data);
        editingCaptionId.value = null;
    } catch {
        toast.error('Failed to save caption.');
    }
};

const activeRelease = computed(() => releases.value.find((r) => r.is_active) ?? null);

onMounted(fetchOverview);
</script>

<template>
    <Head title="APK Distribution" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4 sm:p-6">
            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-cyan-500/10 text-cyan-500">
                        <Smartphone class="h-5 w-5" />
                    </div>
                    <div>
                        <h1 class="text-lg font-semibold">APK Distribution</h1>
                        <p class="text-sm text-muted-foreground">Manage the public download page, app information and releases.</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Badge v-if="activeRelease" variant="outline" class="border-emerald-500/40 text-emerald-500">
                        Active: v{{ activeRelease.version_name }}
                    </Badge>
                    <Button variant="outline" size="sm" as-child>
                        <a href="/apk" target="_blank" rel="noopener">
                            <ExternalLink class="mr-1.5 h-3.5 w-3.5" /> View Landing Page
                        </a>
                    </Button>
                </div>
            </div>

            <div v-if="loading" class="flex items-center justify-center py-20 text-muted-foreground">
                <Loader2 class="mr-2 h-5 w-5 animate-spin" /> Loading…
            </div>

            <div v-else class="grid gap-6 xl:grid-cols-2">
                <!-- App information -->
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">App Information</CardTitle>
                        <CardDescription>Shown on the public landing page at /apk.</CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div class="flex items-center gap-4">
                            <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border bg-muted">
                                <img v-if="logoPreview || currentLogoUrl" :src="logoPreview ?? currentLogoUrl ?? ''" alt="Logo" class="h-full w-full object-cover" />
                                <ImageIcon v-else class="h-6 w-6 text-muted-foreground" />
                            </div>
                            <div class="flex-1">
                                <Label class="mb-1.5 block text-xs">App Logo</Label>
                                <Input type="file" accept="image/*" class="text-xs" @change="onLogoSelected" />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label class="mb-1.5 block text-xs">App Title</Label>
                                <Input v-model="form.app_title" placeholder="Blitz SMS Forwarder" />
                            </div>
                            <div>
                                <Label class="mb-1.5 block text-xs">Tagline</Label>
                                <Input v-model="form.tagline" placeholder="Real-time SMS to portal." />
                            </div>
                        </div>

                        <div>
                            <Label class="mb-1.5 block text-xs">Description</Label>
                            <textarea
                                v-model="form.description"
                                rows="4"
                                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                placeholder="What does the app do?"
                            ></textarea>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label class="mb-1.5 block text-xs">Package Name</Label>
                                <Input v-model="form.package_name" placeholder="com.example.app" class="font-mono text-xs" />
                            </div>
                            <div>
                                <Label class="mb-1.5 block text-xs">Developer Name</Label>
                                <Input v-model="form.developer_name" placeholder="Senda Japan Ltd" />
                            </div>
                            <div>
                                <Label class="mb-1.5 block text-xs">Developer Email</Label>
                                <Input v-model="form.developer_email" type="email" placeholder="dev@example.com" />
                            </div>
                            <div>
                                <Label class="mb-1.5 block text-xs">Developer Website</Label>
                                <Input v-model="form.developer_website" placeholder="https://example.com" />
                            </div>
                        </div>

                        <!-- Features -->
                        <div>
                            <Label class="mb-1.5 block text-xs">Features (shown as capability cards)</Label>
                            <div class="flex flex-wrap gap-2">
                                <Badge v-for="(feature, i) in features" :key="i" variant="secondary" class="gap-1.5 pr-1">
                                    {{ feature }}
                                    <button type="button" class="rounded-full p-0.5 hover:bg-foreground/10" @click="removeFeature(i)">
                                        <X class="h-3 w-3" />
                                    </button>
                                </Badge>
                            </div>
                            <div class="mt-2 flex gap-2">
                                <Input v-model="newFeature" placeholder="Add a feature…" class="text-sm" @keydown.enter.prevent="addFeature" />
                                <Button type="button" variant="outline" size="icon" @click="addFeature"><Plus class="h-4 w-4" /></Button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between rounded-lg border px-4 py-3">
                            <div>
                                <div class="text-sm font-medium">Landing page published</div>
                                <div class="text-xs text-muted-foreground">When off, /apk returns 404.</div>
                            </div>
                            <Switch v-model="form.is_published" />
                        </div>

                        <Button :disabled="savingInfo" @click="saveInfo">
                            <Loader2 v-if="savingInfo" class="mr-1.5 h-4 w-4 animate-spin" />
                            <Save v-else class="mr-1.5 h-4 w-4" />
                            Save App Information
                        </Button>
                    </CardContent>
                </Card>

                <div class="flex flex-col gap-6">
                    <!-- Upload release -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">Upload New Release</CardTitle>
                            <CardDescription>Upload an .apk and optionally make it the active download.</CardDescription>
                        </CardHeader>
                        <CardContent class="flex flex-col gap-4">
                            <div>
                                <Label class="mb-1.5 block text-xs">APK File</Label>
                                <Input type="file" accept=".apk" class="text-xs" @change="onApkSelected" />
                            </div>
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <Label class="mb-1.5 block text-xs">Version Name *</Label>
                                    <Input v-model="releaseForm.version_name" placeholder="2.1" />
                                </div>
                                <div>
                                    <Label class="mb-1.5 block text-xs">Version Code</Label>
                                    <Input v-model="releaseForm.version_code" type="number" placeholder="21" />
                                </div>
                                <div>
                                    <Label class="mb-1.5 block text-xs">Min Android</Label>
                                    <Input v-model="releaseForm.min_android" placeholder="8.0" />
                                </div>
                            </div>
                            <div>
                                <Label class="mb-1.5 block text-xs">Changelog (one item per line)</Label>
                                <textarea
                                    v-model="releaseForm.changelog"
                                    rows="3"
                                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    placeholder="Fixed crash on boot&#10;New setup wizard"
                                ></textarea>
                            </div>
                            <div class="flex items-center justify-between">
                                <label class="flex items-center gap-2 text-sm">
                                    <Switch v-model="releaseForm.activate" />
                                    Activate immediately
                                </label>
                                <Button :disabled="uploadingRelease" @click="uploadRelease">
                                    <Loader2 v-if="uploadingRelease" class="mr-1.5 h-4 w-4 animate-spin" />
                                    <UploadCloud v-else class="mr-1.5 h-4 w-4" />
                                    {{ uploadingRelease ? `Uploading ${uploadProgress}%` : 'Upload Release' }}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Screenshots -->
                    <Card>
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <div>
                                    <CardTitle class="text-base">Screenshots</CardTitle>
                                    <CardDescription>Displayed in the landing page gallery, in order.</CardDescription>
                                </div>
                                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-1.5 text-xs font-medium hover:bg-accent">
                                    <Loader2 v-if="uploadingScreenshots" class="h-3.5 w-3.5 animate-spin" />
                                    <UploadCloud v-else class="h-3.5 w-3.5" />
                                    Upload
                                    <input type="file" accept="image/*" multiple class="hidden" @change="onScreenshotsSelected" />
                                </label>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <p v-if="screenshots.length === 0" class="py-6 text-center text-sm text-muted-foreground">No screenshots yet.</p>
                            <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                <div v-for="(shot, index) in screenshots" :key="shot.id" class="group relative overflow-hidden rounded-lg border">
                                    <img :src="shot.url" :alt="shot.caption ?? 'Screenshot'" class="aspect-[9/16] w-full object-cover" />
                                    <div class="absolute inset-x-0 top-0 flex justify-between gap-1 bg-gradient-to-b from-black/70 to-transparent p-1.5 opacity-0 transition-opacity group-hover:opacity-100">
                                        <div class="flex gap-1">
                                            <button class="rounded bg-black/50 p-1 text-white hover:bg-black/80 disabled:opacity-30" :disabled="index === 0" @click="moveScreenshot(index, -1)">
                                                <ArrowUp class="h-3.5 w-3.5" />
                                            </button>
                                            <button class="rounded bg-black/50 p-1 text-white hover:bg-black/80 disabled:opacity-30" :disabled="index === screenshots.length - 1" @click="moveScreenshot(index, 1)">
                                                <ArrowDown class="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                        <div class="flex gap-1">
                                            <button class="rounded bg-black/50 p-1 text-white hover:bg-black/80" @click="startCaptionEdit(shot)">
                                                <Pencil class="h-3.5 w-3.5" />
                                            </button>
                                            <button class="rounded bg-red-600/70 p-1 text-white hover:bg-red-600" :disabled="busyScreenshotId === shot.id" @click="deleteScreenshot(shot)">
                                                <Trash2 class="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                    </div>
                                    <div v-if="editingCaptionId === shot.id" class="absolute inset-x-0 bottom-0 flex gap-1 bg-black/80 p-1.5">
                                        <input v-model="captionDraft" class="w-full rounded bg-white/10 px-2 py-1 text-xs text-white outline-none" placeholder="Caption…" @keydown.enter="saveCaption(shot)" />
                                        <button class="rounded bg-emerald-600 p-1 text-white" @click="saveCaption(shot)"><Check class="h-3.5 w-3.5" /></button>
                                    </div>
                                    <div v-else-if="shot.caption" class="absolute inset-x-0 bottom-0 truncate bg-black/60 px-2 py-1 text-[11px] text-white">{{ shot.caption }}</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- Releases table -->
            <Card v-if="!loading">
                <CardHeader>
                    <CardTitle class="text-base">Releases</CardTitle>
                    <CardDescription>The active release is served at /apk/download.</CardDescription>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Version</TableHead>
                                <TableHead>File</TableHead>
                                <TableHead>Size</TableHead>
                                <TableHead>SHA-256</TableHead>
                                <TableHead>Downloads</TableHead>
                                <TableHead>Released</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="releases.length === 0">
                                <TableCell colspan="8" class="py-8 text-center text-muted-foreground">No releases uploaded yet.</TableCell>
                            </TableRow>
                            <TableRow v-for="release in releases" :key="release.id">
                                <TableCell class="font-medium">v{{ release.version_name }}<span v-if="release.version_code" class="ml-1 text-xs text-muted-foreground">({{ release.version_code }})</span></TableCell>
                                <TableCell class="max-w-[11.25rem] truncate font-mono text-xs">{{ release.file_name }}</TableCell>
                                <TableCell class="text-xs">{{ formatSize(release.file_size) }}</TableCell>
                                <TableCell class="font-mono text-[10px] text-muted-foreground">{{ release.checksum_sha256.slice(0, 16) }}…</TableCell>
                                <TableCell class="text-xs">{{ release.download_count.toLocaleString() }}</TableCell>
                                <TableCell class="text-xs">{{ formatDate(release.released_at) }}</TableCell>
                                <TableCell>
                                    <Badge v-if="release.is_active" variant="outline" class="border-emerald-500/40 text-emerald-500">
                                        <CheckCircle2 class="mr-1 h-3 w-3" /> Active
                                    </Badge>
                                    <Badge v-else variant="secondary">Archived</Badge>
                                </TableCell>
                                <TableCell class="text-right">
                                    <div class="flex justify-end gap-1.5">
                                        <Button v-if="!release.is_active" variant="outline" size="sm" :disabled="busyReleaseId === release.id" @click="activateRelease(release)">
                                            Activate
                                        </Button>
                                        <Button variant="outline" size="sm" class="border-zinc-200 dark:border-zinc-700 text-red-500 dark:text-red-400 hover:bg-zinc-100 dark:hover:bg-zinc-800" :disabled="busyReleaseId === release.id" @click="deleteRelease(release)">
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
