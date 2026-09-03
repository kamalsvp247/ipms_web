<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { Loader2, TerminalSquare, Download, Server, Globe, Zap, FileText, Upload, Waypoints, ChevronDown, ChevronUp, Save, RotateCcw, PencilLine, Undo2, UserPlus } from 'lucide-vue-next';
import { computed, defineComponent, h, onBeforeUnmount, onMounted, reactive, ref, watch, type PropType } from 'vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface AccountPdfItem { id: number; name: string; is_primary: boolean; size: number | null }
interface AccountOption { id: number; phone: string; email: string; tag: string | null; appointment_id: string | null; jwt_expires_at: string | null; pdfs: AccountPdfItem[] }
interface BypassIpOption { id: number; label: string; ip: string; is_default: boolean }
interface SessionData { token: string; expiresAt: string | null; generatedAt: string | null; requestId: string | null }

interface IvacResult {
    method: string;
    url: string;
    bypass_ip: string | null;
    status_code: number;
    body: unknown;
    raw: string;
    duration_ms: number;
    error: string | null;
    location?: string | null;
}

interface FileOverviewItem {
    applicationId: string | null;
    commissionName: string | null;
    dob: string | null;
    email: string | null;
    fullName: string | null;
    ivacCenter: string | null;
    nidOrBr: string | null;
    passport: string | null;
    phone: string | null;
    primary: boolean;
    visaType: string | null;
}

interface BookingConfigData {
    appointmentDate: string | null;
    appointmentId: string | null;
    appointmentSlot: string | null;
    fileUploadStatus: string | null;
    ivacCenter: string | null;
    mission: string | null;
    numberOfApplicants: number | null;
    totalAmount: number | null;
}

interface AccountSidebarData {
    fileOverview: { items: FileOverviewItem[]; fetchedAt: string } | null;
    bookingConfig: { data: BookingConfigData; fetchedAt: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'API Tester', href: '/api-tester' },
];

interface PdfProfile { surname: string; given_name: string; passport_no: string; pdf_phone: string; email: string }

interface EndpointMeta {
    label: string;
    group: 'path' | 'header';
    header?: string;
    sync: 'auto' | 'manual';
    anchor?: string;
    note?: string;
}

const page = usePage();
// Editing IVAC endpoints/headers rewrites the same settings row the Java bot reads from — gate
// it the same way the dedicated /ivac-endpoints page does.
const canManageEndpoints = computed(() => page.props.auth.permissions?.['bot.manage'] ?? false);

const accounts = ref<AccountOption[]>([]);
const bypassIps = ref<BypassIpOption[]>([]);
const pdfProfiles = ref<Record<string, PdfProfile>>({});

// Protocol constants panel — every IVAC path + rotating header the tester's own calls now use
// (see ApiTesterController::ivacEndpoints()), editable in place so a bundle rotation can be
// patched without leaving the tester. Backed by the same /api/ivac-endpoints the Java bot's
// settings.ivac_endpoints column and the dedicated /ivac-endpoints page use — one source of truth.
const showEndpointsPanel = ref(false);
const endpointsLoading = ref(false);
const endpointsLoaded = ref(false);
const endpointsSaving = ref(false);
const endpointsError = ref('');
const endpointsMeta = ref<Record<string, EndpointMeta>>({});
const endpointsDefaults = ref<Record<string, string>>({});
const endpointsForm = reactive<Record<string, string>>({});
const endpointsSaved = reactive<Record<string, string>>({});

const endpointKeys = computed(() => Object.keys(endpointsMeta.value));
const endpointPathKeys = computed(() => endpointKeys.value.filter((k) => endpointsMeta.value[k].group === 'path'));
const endpointHeaderKeys = computed(() => endpointKeys.value.filter((k) => endpointsMeta.value[k].group === 'header'));
const endpointsDirty = computed(() => endpointKeys.value.some((k) => (endpointsForm[k] ?? '') !== (endpointsSaved[k] ?? '')));

/** Live path for a step description, sourced from the same settings.ivac_endpoints values the
 *  request itself uses (hydrated on mount for every user via context(), not just bot.manage) —
 *  never a hardcoded string that can drift from the endpoint actually being called. */
function livePath(key: string): string {
    return endpointsForm[key] || '…';
}

function endpointFieldError(key: string): string | null {
    const info = endpointsMeta.value[key];
    if (!info) return null;
    const value = (endpointsForm[key] ?? '').trim();
    if (value === '') return `${info.label} cannot be empty.`;
    if (info.group === 'path' && !value.startsWith('/')) return 'Must start with "/".';
    if (info.anchor && !value.includes(info.anchor)) return `Must contain "${info.anchor}".`;
    return null;
}

const endpointsHasErrors = computed(() => endpointKeys.value.some((k) => endpointFieldError(k) !== null));

/** Hydrates form + saved snapshots from a plain {key: value} map — used both by the lightweight
 *  context() payload (values only, so fields show correctly before the full meta ever loads) and
 *  by the full /api/ivac-endpoints response. */
function applyEndpoints(values: Record<string, string>): void {
    for (const key of Object.keys(values)) {
        endpointsForm[key] = values[key];
        endpointsSaved[key] = values[key];
    }
}

async function loadEndpointsMeta(): Promise<void> {
    if (endpointsLoading.value || !canManageEndpoints.value) return;
    endpointsLoading.value = true;
    endpointsError.value = '';
    try {
        const res = await axios.get('/api/ivac-endpoints');
        endpointsMeta.value = res.data.meta ?? {};
        endpointsDefaults.value = res.data.defaults ?? {};
        applyEndpoints(res.data.endpoints ?? {});
        endpointsLoaded.value = true;
    } catch {
        endpointsError.value = 'Failed to load endpoints (need bot.manage permission).';
    } finally {
        endpointsLoading.value = false;
    }
}

function toggleEndpointsPanel(): void {
    showEndpointsPanel.value = !showEndpointsPanel.value;
    if (showEndpointsPanel.value && !endpointsLoaded.value) {
        loadEndpointsMeta();
    }
}

async function saveEndpoints(): Promise<void> {
    if (endpointsHasErrors.value) return;
    endpointsSaving.value = true;
    endpointsError.value = '';
    try {
        const payload: Record<string, string> = {};
        for (const key of endpointKeys.value) {
            payload[key] = (endpointsForm[key] ?? '').trim();
        }
        const res = await axios.post('/api/ivac-endpoints', { endpoints: payload });
        applyEndpoints(res.data.endpoints ?? {});
    } catch (e: unknown) {
        endpointsError.value = axios.isAxiosError(e) && e.response?.status === 422
            ? 'Validation failed — check the highlighted fields.'
            : 'Save failed.';
    } finally {
        endpointsSaving.value = false;
    }
}

async function resetEndpointsToDefaults(): Promise<void> {
    if (!confirm('Reset every IVAC path and header back to the compiled-in defaults? This clears all manual overrides.')) {
        return;
    }
    endpointsSaving.value = true;
    endpointsError.value = '';
    try {
        const res = await axios.post('/api/ivac-endpoints/reset');
        applyEndpoints(res.data.endpoints ?? {});
    } catch {
        endpointsError.value = 'Reset failed.';
    } finally {
        endpointsSaving.value = false;
    }
}

const accountId = ref<number | null>(null);
const bypassIpId = ref<number | null>(null);
const useBypass = ref(false);
const activeTab = ref<'flow' | 'documents' | 'signup'>('flow');

const accessToken = ref('');
const fpRequestId = ref('');
const signinRequestId = ref('');
const otpCode = ref('');
const verifyRequestIdSource = ref<'fp' | 'signin' | 'custom'>('signin');
const customRequestId = ref('');
const otpChannel = ref<'phone' | 'email'>('phone');
const appointmentId = ref('');
const appointmentDate = ref(new Date().toISOString().slice(0, 10));
const gateway = ref<'dg-epay' | 'ssl'>('dg-epay');
// Deployment-scoped UUID IVAC bakes into the bundle for the dg-epay path:
// POST /payment/{paymentSlotId}/dg-epay/initiate. Rotates on redeploy. Hydrated from the portal's
// live settings.payment_config_id on load (see onMounted) so it never drifts from what the bot
// actually sends; left blank here — the backend falls back to the same setting if left blank.
const paymentSlotId = ref('');
// The rotating UUID substituted into the Reserve Slot path template. Hydrated from
// settings.reserve_slot_id on load, same source as paymentSlotId above.
const reserveSlotIdSetting = ref('');

// Per-step endpoint override — the editable box holds the PATH only; the immutable base URL is
// rendered beside it as a static prefix so there is no way to accidentally break the host while
// editing a path. Each call function sends whatever is here as `url`, and the backend prepends
// its own BASE_URL to any relative value (ApiTesterController::resolveUrl), so a path is enough.
// Editing a box never touches the persisted settings.ivac_endpoints the bot reads.
const ivacBaseUrl = ref('');
const stepPath = reactive<Record<string, string>>({});

function defaultPath(key: string): string {
    switch (key) {
        case 'createAppointment':
            return '/appointment';
        // Registration paths are not part of settings.ivac_endpoints (the bot never signs up),
        // so they carry their bundle-extracted literals here rather than through livePath().
        case 'signupSendOtp':
            return '/otp/signupOtp';
        case 'signupVerifyOtp':
            return '/otp/verify-otp';
        case 'signup':
            return '/auth/signup';
        case 'reserveSlot':
            return livePath('reserveSlot').replace('{reserveSlotId}', reserveSlotIdSetting.value || '{reserveSlotId}');
        case 'payment':
            return livePath('payment').replace('{paymentConfigId}', paymentSlotId.value || '{paymentConfigId}');
        default:
            return livePath(key);
    }
}

function initStepPaths(): void {
    for (const key of ['signin', 'sendOtp', 'verifyOtp', 'createAppointment', 'getBookingConfig', 'reserveSlot', 'payment',
        'signupSendOtp', 'signupVerifyOtp', 'signup']) {
        stepPath[key] = defaultPath(key);
    }
}

// Per-account sidebar cache
const perAccountSidebar = ref<Record<number, AccountSidebarData>>({});
const sidebarData = computed<AccountSidebarData | null>(() =>
    accountId.value ? (perAccountSidebar.value[accountId.value] ?? null) : null
);

function ensureSidebar(id: number): AccountSidebarData {
    if (!perAccountSidebar.value[id]) {
        perAccountSidebar.value[id] = { fileOverview: null, bookingConfig: null };
    }
    return perAccountSidebar.value[id];
}

function fmtTime(iso: string): string {
    return new Date(iso).toLocaleString();
}

function fmtExpiresIn(iso: string | null): string {
    if (!iso) return 'no token';
    const secs = Math.floor((new Date(iso).getTime() - Date.now()) / 1000);
    if (secs <= 0) return 'expired';
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return m > 0 ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`;
}

// Per-account JWT state
const perAccountSessions = ref<Record<number, SessionData>>({});
const jwtInput = ref('');
const savingToken = ref(false);
const secondsLeft = ref<number | null>(null);
let countdownTimer: ReturnType<typeof setInterval> | null = null;

function startCountdown(expiresAt: string | null): void {
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
    if (!expiresAt) { secondsLeft.value = null; return; }
    const update = () => { secondsLeft.value = Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000); };
    update();
    countdownTimer = setInterval(update, 1000);
}

function applySession(session: SessionData): void {
    accessToken.value = session.token;
    jwtInput.value = session.token;
    startCountdown(session.expiresAt);
    if (session.requestId) signinRequestId.value = session.requestId;
}

async function loadAccountSession(id: number): Promise<void> {
    try {
        const res = await axios.get(`/api/api-tester/session/${id}`);
        const session: SessionData = {
            token: res.data.jwt_token ?? '',
            expiresAt: res.data.jwt_expires_at ?? null,
            generatedAt: res.data.jwt_generated_at ?? null,
            requestId: res.data.request_id ?? null,
        };
        perAccountSessions.value[id] = session;
        applySession(session);
        const sidebar = ensureSidebar(id);
        if (res.data.last_booking_config) {
            sidebar.bookingConfig = {
                data: res.data.last_booking_config.data,
                fetchedAt: res.data.last_booking_config.fetched_at,
            };
        }
        if (res.data.last_file_overview) {
            sidebar.fileOverview = {
                items: res.data.last_file_overview.items,
                fetchedAt: res.data.last_file_overview.fetched_at,
            };
        }
    } catch {
        accessToken.value = '';
        jwtInput.value = '';
        secondsLeft.value = null;
    }
}

watch(accountId, (newId) => {
    accessToken.value = '';
    jwtInput.value = '';
    secondsLeft.value = null;
    pdfUploadStatus.value = {};
    bookingConfigSetResult.value = null;
    signinRequestId.value = '';
    fpRequestId.value = '';
    if (newId) {
        loadAccountSession(newId);
        localStorage.setItem('apiTester.accountId', String(newId));
        const acct = accounts.value.find((a) => a.id === newId);
        if (acct?.appointment_id) {
            appointmentId.value = acct.appointment_id;
        }
        const phone = acct?.phone;
        if (phone && pdfProfiles.value[phone]) {
            const p = pdfProfiles.value[phone];
            editSurname.value    = p.surname    ?? '';
            editGivenName.value  = p.given_name  ?? '';
            editPassportNo.value = p.passport_no ?? '';
            editPhone.value      = p.pdf_phone   ?? (acct?.phone ?? '');
            editEmail.value      = p.email       ?? (acct?.email ?? '');
        } else {
            editSurname.value    = '';
            editGivenName.value  = '';
            editPassportNo.value = '';
            editPhone.value      = acct?.phone ?? '';
            editEmail.value      = acct?.email ?? '';
        }
    } else {
        localStorage.removeItem('apiTester.accountId');
    }
});

watch(bypassIpId, (newId) => {
    if (newId) {
        localStorage.setItem('apiTester.bypassIpId', String(newId));
    } else {
        localStorage.removeItem('apiTester.bypassIpId');
    }
});

async function saveManualToken(): Promise<void> {
    if (!accountId.value || !jwtInput.value.trim()) return;
    savingToken.value = true;
    try {
        const res = await axios.put(`/api/api-tester/session/${accountId.value}`, { jwt_token: jwtInput.value.trim() });
        const session: SessionData = {
            token: res.data.jwt_token,
            expiresAt: res.data.jwt_expires_at ?? null,
            generatedAt: res.data.jwt_generated_at ?? null,
            requestId: res.data.request_id ?? null,
        };
        perAccountSessions.value[accountId.value] = session;
        applySession(session);
    } catch { /* silent */ } finally {
        savingToken.value = false;
    }
}

const countdownColor = computed(() => {
    if (secondsLeft.value === null) return 'text-muted-foreground';
    if (secondsLeft.value <= 0) return 'text-red-500 dark:text-red-400';
    if (secondsLeft.value < 120) return 'text-amber-500 dark:text-amber-400';
    return 'text-emerald-500 dark:text-emerald-400';
});

const countdownLabel = computed(() => {
    if (secondsLeft.value === null) return '—';
    if (secondsLeft.value <= 0) return 'Expired';
    const m = Math.floor(secondsLeft.value / 60);
    const s = secondsLeft.value % 60;
    return `${m}m ${String(s).padStart(2, '0')}s`;
});

const fileOverviewResult = ref<IvacResult | null>(null);
const loadingFileOverview = ref(false);

async function callFileOverview(): Promise<void> {
    if (!accessToken.value) return;
    loadingFileOverview.value = true;
    try {
        const res = await axios.post('/api/api-tester/file-overview', {
            access_token: accessToken.value,
            bypass_ip_id: bypassIpId.value,
            account_id: accountId.value,
        });
        fileOverviewResult.value = res.data;
        const items = (res.data.body as { data?: FileOverviewItem[] })?.data;
        if (Array.isArray(items) && accountId.value) {
            ensureSidebar(accountId.value).fileOverview = { items, fetchedAt: new Date().toISOString() };
        }
    } catch (e: unknown) {
        fileOverviewResult.value = errorResult(e);
    } finally {
        loadingFileOverview.value = false;
    }
}

// --- Edit PDF ---
const editPdfFile = ref<File | null>(null);
const editPdfInput = ref<HTMLInputElement | null>(null);
const editSurname = ref('');
const editGivenName = ref('');
const editPassportNo = ref('');
const editPhone = ref('');
const editEmail = ref('');
const editingPdf = ref(false);
const editPdfError = ref<string | null>(null);

function onEditPdfSelect(e: Event): void {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    if (file) { editPdfFile.value = file; editPdfError.value = null; }
}

function onEditPdfDrop(e: DragEvent): void {
    const file = e.dataTransfer?.files?.[0] ?? null;
    if (file && (file.type === 'application/pdf' || file.name.endsWith('.pdf'))) {
        editPdfFile.value = file;
        editPdfError.value = null;
    }
}

function clearEditPdf(): void {
    editPdfFile.value = null;
    editPdfError.value = null;
    if (editPdfInput.value) editPdfInput.value.value = '';
}

async function callEditPdf(): Promise<void> {
    if (!accountId.value || !editPdfFile.value) return;
    editingPdf.value = true;
    editPdfError.value = null;
    try {
        const phone = selectedAccount.value?.phone;
        if (phone) {
            await axios.put('/api/api-tester/pdf-profile', {
                phone,
                surname:     editSurname.value.trim()    || null,
                given_name:  editGivenName.value.trim()  || null,
                passport_no: editPassportNo.value.trim() || null,
                pdf_phone:   editPhone.value.trim()      || null,
                email:       editEmail.value.trim()      || null,
            });
            pdfProfiles.value[phone] = {
                surname:    editSurname.value.trim(),
                given_name: editGivenName.value.trim(),
                passport_no: editPassportNo.value.trim(),
                pdf_phone:  editPhone.value.trim(),
                email:      editEmail.value.trim(),
            };
        }

        const form = new FormData();
        form.append('pdf', editPdfFile.value);
        if (editSurname.value.trim())    form.append('surname',     editSurname.value.trim());
        if (editGivenName.value.trim())  form.append('given_name',  editGivenName.value.trim());
        if (editPassportNo.value.trim()) form.append('passport_no', editPassportNo.value.trim());
        if (editPhone.value.trim())      form.append('phone',       editPhone.value.trim());
        if (editEmail.value.trim())      form.append('email',       editEmail.value.trim());
        const res = await axios.post('/api/api-tester/edit-pdf', form, {
            headers: { 'Content-Type': 'multipart/form-data' },
            responseType: 'blob',
        });
        const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = 'edited_passport.pdf';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } catch (e: unknown) {
        editPdfError.value = e instanceof Error ? e.message : 'Edit failed';
    } finally {
        editingPdf.value = false;
    }
}

// --- Upload PDF (account's attached documents) ---
type PdfUploadState = 'idle' | 'uploading' | 'success' | 'failed';
const pdfUploadStatus = ref<Record<number, { state: PdfUploadState; result: IvacResult | null }>>({});
const uploadingAllPdfs = ref(false);

const accountPdfs = computed<AccountPdfItem[]>(() => {
    const list = selectedAccount.value?.pdfs ?? [];
    return [...list].sort((a, b) => Number(b.is_primary) - Number(a.is_primary));
});

function pdfState(id: number): PdfUploadState {
    return pdfUploadStatus.value[id]?.state ?? 'idle';
}

async function uploadOneAccountPdf(pdf: AccountPdfItem): Promise<boolean> {
    if (!accessToken.value) return false;
    pdfUploadStatus.value[pdf.id] = { state: 'uploading', result: null };
    try {
        const captcha = await fetchCaptcha('raw');
        const res = await axios.post('/api/api-tester/upload-account-pdf', {
            account_id: accountId.value,
            pdf_id: pdf.id,
            access_token: accessToken.value,
            bypass_ip_id: bypassIpId.value,
            captcha_token: captcha || undefined,
        });
        const ok = res.data.status_code >= 200 && res.data.status_code < 300;
        pdfUploadStatus.value[pdf.id] = { state: ok ? 'success' : 'failed', result: res.data };
        return ok;
    } catch (e: unknown) {
        pdfUploadStatus.value[pdf.id] = { state: 'failed', result: errorResult(e) };
        return false;
    } finally {
        captchaStatus.value = '';
    }
}

async function uploadAllAccountPdfs(): Promise<void> {
    if (!accessToken.value || accountPdfs.value.length === 0) return;
    uploadingAllPdfs.value = true;
    try {
        const primary = accountPdfs.value.find((p) => p.is_primary);
        const secondaries = accountPdfs.value.filter((p) => !p.is_primary);
        // IVAC attaches secondaries to the primary's record and 404s them if they race
        // ahead, so upload the primary first and await it before firing the rest async.
        if (primary && !(await uploadOneAccountPdf(primary))) {
            return;
        }
        await Promise.all(secondaries.map((p) => uploadOneAccountPdf(p)));
    } finally {
        uploadingAllPdfs.value = false;
    }
}

const CITY_CONFIG: Record<string, { mission: string; ivacCenter: string }> = {
    Dhaka:      { mission: 'Dhaka',      ivacCenter: 'IVAC, Dhaka (JFP)' },
    Khulna:     { mission: 'Khulna',     ivacCenter: 'IVAC, KHULNA' },
    Chittagong: { mission: 'Chittagong', ivacCenter: 'IVAC, CHITTAGONG' },
    Rajshahi:   { mission: 'Rajshahi',   ivacCenter: 'IVAC, RAJSHAHI' },
    Sylhet:     { mission: 'Sylhet',     ivacCenter: 'IVAC, SYLHET' },
};

const selectedCity = ref<string>('Dhaka');
const bookingConfigPayload = computed(() => CITY_CONFIG[selectedCity.value] ?? null);
const settingBookingConfig = ref(false);
const bookingConfigSetResult = ref<IvacResult | null>(null);

async function callSetBookingConfig(): Promise<void> {
    if (!accessToken.value || !bookingConfigPayload.value) return;
    settingBookingConfig.value = true;
    try {
        const res = await axios.post('/api/api-tester/set-booking-config', {
            bypass_ip_id: bypassIpId.value,
            access_token: accessToken.value,
            city: selectedCity.value,
        });
        bookingConfigSetResult.value = res.data;
    } catch (e: unknown) {
        bookingConfigSetResult.value = errorResult(e);
    } finally {
        settingBookingConfig.value = false;
    }
}

const results = ref<Record<string, IvacResult | null>>({
    signin: null, sendOtp: null, verifyOtp: null, createAppointment: null, bookingConfig: null, reserveSlot: null, payment: null, paymentCallback: null,
});
const busy = ref<Record<string, boolean>>({
    signin: false, sendOtp: false, verifyOtp: false, fetchOtp: false, createAppointment: false, bookingConfig: false, reserveSlot: false, payment: false, paymentCallback: false,
});

const captchaStatus = ref<string>('');

const lastSigninCaptcha = ref<string>('');
const lastReserveCaptcha = ref<string>('');
const useManualSigninCaptcha = ref(false);
const manualSigninCaptchaToken = ref('');
const skipSigninCaptcha = ref(false);
const useSpoofXff = ref(false);
const spoofedIp = ref('');

function generateRandomIp(): string {
    // Random public IPv4 — avoids RFC-1918 private ranges
    const publicRanges = [
        () => `${randInt(1, 9)}.${randInt(0, 255)}.${randInt(0, 255)}.${randInt(1, 254)}`,
        () => `${randInt(11, 126)}.${randInt(0, 255)}.${randInt(0, 255)}.${randInt(1, 254)}`,
        () => `${randInt(128, 171)}.${randInt(0, 255)}.${randInt(0, 255)}.${randInt(1, 254)}`,
        () => `${randInt(173, 191)}.${randInt(0, 255)}.${randInt(0, 255)}.${randInt(1, 254)}`,
        () => `${randInt(193, 223)}.${randInt(0, 255)}.${randInt(0, 255)}.${randInt(1, 254)}`,
    ];
    return publicRanges[Math.floor(Math.random() * publicRanges.length)]();
}

function randInt(min: number, max: number): number {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}
const lastRawCaptcha = ref<string>('');
const lastPaymentCaptcha = ref<string>('');
const lastInvoiceCaptcha = ref<string>('');

const loopingSignin = ref(false);
const loopingVerify = ref(false);
const loopingReserve = ref(false);
const loopingPayment = ref(false);
const loopingCallback = ref(false);
const callbackUrlInput = ref('');
const txrId = ref('');
const downloadingInvoice = ref(false);

watch(callbackUrlInput, (url) => {
    if (!url) return;
    try {
        const u = new URL(url.trim());
        const t = u.searchParams.get('tran_id');
        if (t) txrId.value = t;
    } catch { /* ignore */ }
});

const loopCount = ref<Record<string, number>>({ signin: 0, verifyOtp: 0, reserveSlot: 0, payment: 0, paymentCallback: 0 });

const LOOP_MAX_ITERATIONS = 500;
const LOOP_GAP_MS = 200;
const LOOP_MAX_CONSECUTIVE_ERRORS = 10;

const loopTimers: Record<string, ReturnType<typeof setTimeout> | null> = {
    signin: null, verify: null, reserve: null, payment: null, callback: null,
};

function cancellableSleep(key: string, ms: number, shouldContinue: () => boolean): Promise<void> {
    return new Promise((resolve) => {
        if (!shouldContinue()) { resolve(); return; }
        loopTimers[key] = setTimeout(() => {
            loopTimers[key] = null;
            resolve();
        }, ms);
    });
}

function abortLoopTimer(key: string): void {
    if (loopTimers[key] !== null) {
        clearTimeout(loopTimers[key]!);
        loopTimers[key] = null;
    }
}

function abortAllLoopTimers(): void {
    Object.keys(loopTimers).forEach((k) => abortLoopTimer(k));
}

const selectedAccount = computed(() => accounts.value.find((a) => a.id === accountId.value) ?? null);
const selectedPdfProfile = computed(() => {
    const phone = selectedAccount.value?.phone;
    return phone ? (pdfProfiles.value[phone] ?? null) : null;
});

onMounted(async () => {
    const res = await axios.get('/api/api-tester/context');
    accounts.value = (res.data.accounts as AccountOption[]).sort((a, b) => a.id - b.id);
    bypassIps.value = res.data.bypass_ips;
    pdfProfiles.value = res.data.pdf_profiles ?? {};
    if (res.data.payment_config_id && !paymentSlotId.value) {
        paymentSlotId.value = res.data.payment_config_id;
    }
    if (res.data.reserve_slot_id) {
        reserveSlotIdSetting.value = res.data.reserve_slot_id;
    }
    ivacBaseUrl.value = res.data.ivac_base_url ?? '';
    applyEndpoints(res.data.ivac_endpoints ?? {});
    initStepPaths();

    const savedAccount = Number(localStorage.getItem('apiTester.accountId') || '0');
    const savedBypass = Number(localStorage.getItem('apiTester.bypassIpId') || '0');

    const requestedPhone = new URLSearchParams(window.location.search).get('phone');
    const phoneMatch = requestedPhone ? accounts.value.find((a) => a.phone === requestedPhone) : null;

    if (phoneMatch) {
        accountId.value = phoneMatch.id;
    } else if (savedAccount && accounts.value.some((a) => a.id === savedAccount)) {
        accountId.value = savedAccount;
    }

    const savedUseBypass = localStorage.getItem('apiTester.useBypass');
    if (savedUseBypass !== null) useBypass.value = savedUseBypass !== 'false';

    const savedTab = localStorage.getItem('apiTester.tab');
    if (savedTab && ['flow', 'documents', 'signup'].includes(savedTab)) {
        activeTab.value = savedTab as typeof activeTab.value;
    }

    if (useBypass.value) {
        if (savedBypass && bypassIps.value.some((b) => b.id === savedBypass)) {
            bypassIpId.value = savedBypass;
        } else {
            const def = bypassIps.value.find((b) => b.is_default);
            if (def) bypassIpId.value = def.id;
        }
    } else {
        bypassIpId.value = null;
    }
});

async function fetchCaptcha(type: 'turnstile' | 'turnstile_encrypted' | 'raw', phone?: string): Promise<string> {
    captchaStatus.value = `Requesting captcha (${type})…`;
    // `phone` only tags the solve request for attribution; the signup flow passes its own number
    // because there is no selected account behind it yet.
    const create = await axios.post('/api/captcha/request', { phone: phone ?? selectedAccount.value?.phone });
    const requestId = create.data.request_id;
    const deadline = Date.now() + 120_000;
    while (Date.now() < deadline) {
        await new Promise((r) => setTimeout(r, 1000));
        try {
            const r = await axios.get(`/api/captcha/request/${requestId}`, { params: { type } });
            if (r.data.status === 'ready') {
                captchaStatus.value = `Captcha ready (${type})`;
                return r.data.token as string;
            }
            if (r.data.status === 'failed') {
                throw new Error('Captcha failed: ' + (r.data.error ?? 'unknown'));
            }
            captchaStatus.value = `Captcha pending (${type})…`;
        } catch (e: unknown) {
            if (axios.isAxiosError(e) && e.response?.status === 404) continue;
            throw e;
        }
    }
    throw new Error('Captcha timeout');
}

function isCaptchaDisabledError(e: unknown): boolean {
    return e instanceof Error && e.message.includes('No enabled captcha providers');
}

// --- Sign Up (account registration) ---
// Mirrors the bundle's /signup -> /signup/info -> /signup/password -> /signup/consent wizard:
// both channels get their own OTP + verification, then one POST /auth/signup carries the profile.
type SignupChannel = 'phone' | 'email';

const signupForm = reactive({
    phone: '',
    email: '',
    givenName: '',
    surname: '',
    dob: '',
    nid: '',
    passport: '',
    password: '',
});

const signupPdfInput = ref<HTMLInputElement | null>(null);
const signupPdfBusy = ref(false);
const signupPdfStatus = ref('');
const signupPdfError = ref('');

/** Field key in the parser response paired with the form key it fills and its label. */
const SIGNUP_PDF_FIELDS: Array<[string, keyof typeof signupForm, string]> = [
    ['surname', 'surname', 'surname'],
    ['given_name', 'givenName', 'given name'],
    ['dob', 'dob', 'date of birth'],
    ['nid', 'nid', 'NID'],
    ['passport', 'passport', 'passport'],
    ['phone', 'phone', 'phone'],
    ['email', 'email', 'email'],
];

/**
 * Read the applicant's details out of their visa application form PDF and drop them into the
 * form. Everything stays editable afterwards — this only saves the typing, and fields the PDF
 * does not carry are left untouched rather than blanked.
 */
async function onSignupPdfSelected(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) { return; }

    signupPdfBusy.value = true;
    signupPdfStatus.value = '';
    signupPdfError.value = '';
    try {
        const form = new FormData();
        form.append('pdf', file);
        const res = await axios.post('/api/api-tester/parse-signup-pdf', form);
        const fields = (res.data.fields ?? {}) as Record<string, string | null>;

        const filled: string[] = [];
        for (const [source, target, label] of SIGNUP_PDF_FIELDS) {
            const value = fields[source];
            if (typeof value === 'string' && value !== '') {
                signupForm[target] = value;
                filled.push(label);
            }
        }

        signupPdfStatus.value = filled.length
            ? `Filled from ${file.name}: ${filled.join(', ')}. Edit anything that looks wrong.`
            : `Nothing readable in ${file.name} — is it an Indian visa application form?`;
    } catch (e: unknown) {
        signupPdfError.value = axios.isAxiosError(e)
            ? ((e.response?.data as { message?: string })?.message ?? e.message)
            : (e instanceof Error ? e.message : String(e));
    } finally {
        signupPdfBusy.value = false;
        // Allow re-selecting the same file after an edit.
        input.value = '';
    }
}

const signupRequestId = reactive<Record<SignupChannel, string>>({ phone: '', email: '' });
const signupOtp = reactive<Record<SignupChannel, string>>({ phone: '', email: '' });
const signupVerified = reactive<Record<SignupChannel, boolean>>({ phone: false, email: false });

const signupResults = ref<Record<string, IvacResult | null>>({
    sendOtpPhone: null, sendOtpEmail: null, verifyOtpPhone: null, verifyOtpEmail: null, signup: null,
});
const signupBusy = ref<Record<string, boolean>>({
    sendOtpPhone: false, sendOtpEmail: false, verifyOtpPhone: false, verifyOtpEmail: false, signup: false, fetchOtp: false,
});

const signupCanSubmit = computed(() =>
    signupForm.phone.trim() !== '' &&
    signupForm.email.trim() !== '' &&
    signupForm.givenName.trim() !== '' &&
    signupForm.surname.trim() !== '' &&
    signupForm.dob !== '' &&
    signupForm.password !== ''
);

/** IVAC wraps every response as `{ data: … }`; both requestId and the verified flag live in there. */
function signupResponseData(result: IvacResult | null): Record<string, unknown> | null {
    const body = result?.body as { data?: unknown } | undefined;
    return body && typeof body.data === 'object' && body.data !== null ? (body.data as Record<string, unknown>) : null;
}

async function callSignupSendOtp(channel: SignupChannel): Promise<void> {
    const key = channel === 'phone' ? 'sendOtpPhone' : 'sendOtpEmail';
    const identifier = channel === 'phone' ? signupForm.phone.trim() : signupForm.email.trim();
    if (!identifier) { alert(`Enter the ${channel} first.`); return; }

    signupBusy.value[key] = true;
    try {
        // Registration OTP is gated by a RAW Turnstile token in x-token — not the encrypted
        // `c` body field sign-in uses.
        const captcha = await fetchCaptcha('raw', signupForm.phone.trim() || undefined);
        const res = await axios.post('/api/api-tester/signup-send-otp', {
            bypass_ip_id: bypassIpId.value,
            channel,
            phone: channel === 'phone' ? identifier : null,
            email: channel === 'email' ? identifier : null,
            captcha_token: captcha,
            url: stepPath.signupSendOtp || undefined,
        });
        signupResults.value[key] = res.data;
        const requestId = signupResponseData(res.data)?.requestId;
        if (typeof requestId === 'string' && requestId) {
            signupRequestId[channel] = requestId;
        }
    } catch (e: unknown) {
        signupResults.value[key] = errorResult(e);
    } finally {
        signupBusy.value[key] = false;
        captchaStatus.value = '';
    }
}

async function callSignupVerifyOtp(channel: SignupChannel): Promise<void> {
    const key = channel === 'phone' ? 'verifyOtpPhone' : 'verifyOtpEmail';
    if (!signupRequestId[channel]) { alert(`No requestId yet — send the ${channel} OTP first.`); return; }
    if (!signupOtp[channel]) { alert('Enter the OTP code.'); return; }

    signupBusy.value[key] = true;
    try {
        const res = await axios.post('/api/api-tester/signup-verify-otp', {
            bypass_ip_id: bypassIpId.value,
            channel,
            phone: channel === 'phone' ? signupForm.phone.trim() : null,
            email: channel === 'email' ? signupForm.email.trim() : null,
            request_id: signupRequestId[channel],
            otp: signupOtp[channel],
            url: stepPath.signupVerifyOtp || undefined,
        });
        signupResults.value[key] = res.data;
        signupVerified[channel] = signupResponseData(res.data)?.verified === true;
    } catch (e: unknown) {
        signupResults.value[key] = errorResult(e);
    } finally {
        signupBusy.value[key] = false;
    }
}

/** Pull the ingested SMS for the number being registered — it has no accounts row yet. */
async function callFetchSignupOtp(): Promise<void> {
    const phone = signupForm.phone.trim();
    if (!phone) { alert('Enter the phone first.'); return; }

    signupBusy.value.fetchOtp = true;
    try {
        const res = await axios.get('/api/api-tester/fetch-otp-by-phone', { params: { phone } });
        if (res.data.otp_code) {
            signupOtp.phone = res.data.otp_code;
        } else {
            alert('No unconsumed OTP ingested for this number yet.');
        }
    } catch (e: unknown) {
        alert(e instanceof Error ? e.message : String(e));
    } finally {
        signupBusy.value.fetchOtp = false;
    }
}

async function callSignup(): Promise<void> {
    if (!signupCanSubmit.value) { alert('Fill in every required field first.'); return; }

    signupBusy.value.signup = true;
    try {
        const res = await axios.post('/api/api-tester/signup', {
            bypass_ip_id: bypassIpId.value,
            phone: signupForm.phone.trim(),
            email: signupForm.email.trim(),
            given_name: signupForm.givenName.trim(),
            surname: signupForm.surname.trim(),
            dob: signupForm.dob,
            nid: signupForm.nid.trim() || null,
            passport: signupForm.passport.trim() || null,
            password: signupForm.password,
            url: stepPath.signup || undefined,
        });
        signupResults.value.signup = res.data;
        // A fresh registration invalidates whatever the previous one saved.
        signupSaveState.value = 'idle';
        signupSaveError.value = '';
    } catch (e: unknown) {
        signupResults.value.signup = errorResult(e);
    } finally {
        signupBusy.value.signup = false;
    }
}

const signupSaveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
const signupSaveError = ref('');

const signupSucceeded = computed(() => {
    const code = signupResults.value.signup?.status_code ?? 0;
    return code >= 200 && code < 300;
});

/**
 * Persist the just-registered applicant as a portal account through the canonical
 * POST /api/accounts, so it inherits AccountService's ownership, duplicate-phone and
 * defaulting rules rather than a second, divergent creation path.
 */
async function saveSignupAsAccount(): Promise<void> {
    if (!signupSucceeded.value) { return; }

    signupSaveState.value = 'saving';
    signupSaveError.value = '';
    try {
        const fullName = `${signupForm.givenName.trim()} ${signupForm.surname.trim()}`.trim();
        await axios.post('/api/accounts', {
            phone: signupForm.phone.trim(),
            email: signupForm.email.trim(),
            password: signupForm.password,
            tag: fullName || null,
        });
        signupSaveState.value = 'saved';

        // Pull the account list back in so the new row is immediately selectable in the
        // booking-flow tab without a page reload.
        const res = await axios.get('/api/api-tester/context');
        accounts.value = (res.data.accounts as AccountOption[]).sort((a, b) => a.id - b.id);
        pdfProfiles.value = res.data.pdf_profiles ?? {};
    } catch (e: unknown) {
        signupSaveState.value = 'error';
        signupSaveError.value = axios.isAxiosError(e)
            ? ((e.response?.data as { message?: string })?.message ?? e.message)
            : (e instanceof Error ? e.message : String(e));
    }
}

async function callSignin(reuseCaptcha = false) {
    if (!accountId.value) return;
    if (reuseCaptcha && !lastSigninCaptcha.value && !skipSigninCaptcha.value) { alert('No captcha cached — run Sign In first.'); return; }
    busy.value.signin = true;
    try {
        let captcha: string | null = null;
        if (skipSigninCaptcha.value) {
            captcha = null;
        } else if (useManualSigninCaptcha.value) {
            const manual = manualSigninCaptchaToken.value.trim();
            if (!manual) { alert('Manual captcha token is empty.'); busy.value.signin = false; return; }
            captcha = manual;
        } else {
            try {
                captcha = reuseCaptcha ? lastSigninCaptcha.value : await fetchCaptcha('turnstile');
            } catch (e: unknown) {
                if (!isCaptchaDisabledError(e)) { throw e; }
                // Captcha providers are toggled off — sign in without a captcha token.
                captcha = null;
                captchaStatus.value = 'Captcha disabled — signing in without captcha';
            }
        }
        if (captcha) lastSigninCaptcha.value = captcha;
        const res = await axios.post('/api/api-tester/signin', {
            account_id: accountId.value,
            bypass_ip_id: bypassIpId.value,
            captcha_token: captcha,
            spoofed_ip: useSpoofXff.value ? spoofedIp.value : null,
            url: stepPath.signin || undefined,
        });
        results.value.signin = res.data;
        const data = (res.data.body as { data?: { accessToken?: string; requestId?: string } })?.data;
        if (data?.accessToken) {
            // Sign-in auto-persists to DB server-side; load fresh session to get expiry
            if (accountId.value) {
                delete perAccountSessions.value[accountId.value];
                await loadAccountSession(accountId.value);
            }
        }
        if (data?.requestId) signinRequestId.value = data.requestId;
    } catch (e: unknown) {
        results.value.signin = errorResult(e);
    } finally {
        busy.value.signin = false;
        captchaStatus.value = '';
    }
}

async function callSigninRaw(reuseCaptcha = false) {
    if (!accountId.value) return;
    if (reuseCaptcha && !lastRawCaptcha.value) { alert('No raw captcha cached — run Sign In (raw) first.'); return; }
    busy.value.signin = true;
    try {
        const captcha = reuseCaptcha ? lastRawCaptcha.value : await fetchCaptcha('raw');
        lastRawCaptcha.value = captcha;
        const res = await axios.post('/api/api-tester/signin', {
            account_id: accountId.value,
            bypass_ip_id: bypassIpId.value,
            captcha_token: captcha,
            spoofed_ip: useSpoofXff.value ? spoofedIp.value : null,
            url: stepPath.signin || undefined,
        });
        results.value.signin = res.data;
        const data = (res.data.body as { data?: { accessToken?: string; requestId?: string } })?.data;
        if (data?.accessToken) {
            if (accountId.value) {
                delete perAccountSessions.value[accountId.value];
                await loadAccountSession(accountId.value);
            }
        }
        if (data?.requestId) signinRequestId.value = data.requestId;
    } catch (e: unknown) {
        results.value.signin = errorResult(e);
    } finally {
        busy.value.signin = false;
        captchaStatus.value = '';
    }
}

async function callSendOtp() {
    if (!accountId.value) return;
    busy.value.sendOtp = true;
    try {
        const res = await axios.post('/api/api-tester/send-otp', {
            account_id: accountId.value,
            bypass_ip_id: bypassIpId.value,
            channel: otpChannel.value,
            url: stepPath.sendOtp || undefined,
        });
        results.value.sendOtp = res.data;
        const reqId = (res.data.body as { data?: { requestId?: string } })?.data?.requestId;
        if (reqId) fpRequestId.value = reqId;
    } catch (e: unknown) {
        results.value.sendOtp = errorResult(e);
    } finally {
        busy.value.sendOtp = false;
    }
}

async function callFetchOtp() {
    if (!accountId.value) return;
    busy.value.fetchOtp = true;
    try {
        const res = await axios.get(`/api/api-tester/fetch-otp/${accountId.value}`);
        if (res.data.otp_code) {
            otpCode.value = res.data.otp_code;
        } else {
            alert('No unconsumed OTP found for this account.');
        }
    } catch (e: unknown) {
        alert('Failed to fetch OTP.');
    } finally {
        busy.value.fetchOtp = false;
    }
}

async function callVerifyOtp() {
    if (!accountId.value || !otpCode.value) return;
    let reqId = '';
    if (verifyRequestIdSource.value === 'fp') reqId = fpRequestId.value;
    else if (verifyRequestIdSource.value === 'signin') reqId = signinRequestId.value;
    else reqId = customRequestId.value.trim();
    if (!reqId) { alert('No requestId — pick a source or paste one.'); return; }
    busy.value.verifyOtp = true;
    try {
        const res = await axios.post('/api/api-tester/verify-otp', {
            account_id: accountId.value,
            bypass_ip_id: bypassIpId.value,
            access_token: accessToken.value,
            request_id: reqId,
            otp: otpCode.value,
            channel: otpChannel.value,
            url: stepPath.verifyOtp || undefined,
        });
        results.value.verifyOtp = res.data;
    } catch (e: unknown) {
        results.value.verifyOtp = errorResult(e);
    } finally {
        busy.value.verifyOtp = false;
    }
}

async function callCreateAppointment() {
    if (!accessToken.value) { alert('No JWT — call Sign In first.'); return; }
    busy.value.createAppointment = true;
    try {
        const res = await axios.post('/api/api-tester/create-appointment', {
            bypass_ip_id: bypassIpId.value,
            access_token: accessToken.value,
            account_id: accountId.value,
            url: stepPath.createAppointment || undefined,
        });
        results.value.createAppointment = res.data;
        const id = (res.data.body as { data?: { appointmentId?: string | number } })?.data?.appointmentId;
        if (id) appointmentId.value = String(id);
    } catch (e: unknown) {
        results.value.createAppointment = errorResult(e);
    } finally {
        busy.value.createAppointment = false;
    }
}

async function callBookingConfig() {
    if (!accessToken.value) { alert('No JWT — call Sign In first.'); return; }
    busy.value.bookingConfig = true;
    try {
        const res = await axios.post('/api/api-tester/booking-config', {
            bypass_ip_id: bypassIpId.value,
            access_token: accessToken.value,
            account_id: accountId.value,
            url: stepPath.getBookingConfig || undefined,
        });
        results.value.bookingConfig = res.data;
        const id = (res.data.body as { data?: { appointmentId?: string | number } })?.data?.appointmentId;
        if (id) appointmentId.value = String(id);
        const configData = (res.data.body as { data?: BookingConfigData })?.data;
        if (configData && accountId.value) {
            ensureSidebar(accountId.value).bookingConfig = { data: configData, fetchedAt: new Date().toISOString() };
        }
    } catch (e: unknown) {
        results.value.bookingConfig = errorResult(e);
    } finally {
        busy.value.bookingConfig = false;
    }
}

async function callReserveSlot(reuseCaptcha = false) {
    if (!accessToken.value) { alert('No JWT — call Sign In first.'); return; }
    if (reuseCaptcha && !lastReserveCaptcha.value) { alert('No captcha cached — run Reserve Slot first.'); return; }
    busy.value.reserveSlot = true;
    try {
        const captcha = reuseCaptcha ? lastReserveCaptcha.value : await fetchCaptcha('turnstile_encrypted');
        lastReserveCaptcha.value = captcha;
        const res = await axios.post('/api/api-tester/reserve-slot', {
            bypass_ip_id: bypassIpId.value,
            access_token: accessToken.value,
            captcha_token: captcha,
            appointment_date: appointmentDate.value,
            url: stepPath.reserveSlot || undefined,
        });
        results.value.reserveSlot = res.data;
    } catch (e: unknown) {
        results.value.reserveSlot = errorResult(e);
    } finally {
        busy.value.reserveSlot = false;
        captchaStatus.value = '';
    }
}

async function loopSigninUntilSuccess() {
    if (loopingSignin.value) {
        loopingSignin.value = false;
        abortLoopTimer('signin');
        return;
    }
    if (!accountId.value) return;
    if (!skipSigninCaptcha.value && !lastSigninCaptcha.value) {
        if (useManualSigninCaptcha.value) {
            const manual = manualSigninCaptchaToken.value.trim();
            if (!manual) { alert('Manual captcha token is empty.'); return; }
            lastSigninCaptcha.value = manual;
        } else {
            try {
                lastSigninCaptcha.value = await fetchCaptcha('turnstile');
            } catch (e: unknown) {
                results.value.signin = errorResult(e);
                captchaStatus.value = '';
                return;
            }
        }
    }
    loopingSignin.value = true;
    loopCount.value.signin = 0;
    let consecutiveErrors = 0;
    try {
        while (loopingSignin.value && loopCount.value.signin < LOOP_MAX_ITERATIONS) {
            loopCount.value.signin++;
            await callSignin(true);
            const r = results.value.signin;
            if (r && r.status_code >= 200 && r.status_code < 300) break;
            if (r && r.status_code === 0) {
                consecutiveErrors++;
                if (consecutiveErrors >= LOOP_MAX_CONSECUTIVE_ERRORS) break;
            } else {
                consecutiveErrors = 0;
            }
            if (!loopingSignin.value) break;
            await cancellableSleep('signin', LOOP_GAP_MS, () => loopingSignin.value);
        }
    } finally {
        loopingSignin.value = false;
        abortLoopTimer('signin');
    }
}

async function loopVerifyOtpUntilSuccess() {
    if (loopingVerify.value) {
        loopingVerify.value = false;
        abortLoopTimer('verify');
        return;
    }
    if (!accountId.value || !otpCode.value) { alert('Need OTP code — fetch or enter it first.'); return; }
    let reqId = '';
    if (verifyRequestIdSource.value === 'fp') reqId = fpRequestId.value;
    else if (verifyRequestIdSource.value === 'signin') reqId = signinRequestId.value;
    else reqId = customRequestId.value.trim();
    if (!reqId) { alert('No requestId — pick a source or paste one.'); return; }
    loopingVerify.value = true;
    loopCount.value.verifyOtp = 0;
    let consecutiveErrors = 0;
    try {
        while (loopingVerify.value && loopCount.value.verifyOtp < LOOP_MAX_ITERATIONS) {
            loopCount.value.verifyOtp++;
            await callVerifyOtp();
            const r = results.value.verifyOtp;
            if (r && r.status_code >= 200 && r.status_code < 300) break;
            if (r && r.status_code === 0) {
                consecutiveErrors++;
                if (consecutiveErrors >= LOOP_MAX_CONSECUTIVE_ERRORS) break;
            } else {
                consecutiveErrors = 0;
            }
            if (!loopingVerify.value) break;
            await cancellableSleep('verify', LOOP_GAP_MS, () => loopingVerify.value);
        }
    } finally {
        loopingVerify.value = false;
        abortLoopTimer('verify');
    }
}

async function loopReserveSlotUntilSuccess() {
    if (loopingReserve.value) {
        loopingReserve.value = false;
        abortLoopTimer('reserve');
        return;
    }
    if (!accessToken.value) { alert('No JWT — call Sign In first.'); return; }
    if (!lastReserveCaptcha.value) {
        try {
            lastReserveCaptcha.value = await fetchCaptcha('turnstile_encrypted');
        } catch (e: unknown) {
            results.value.reserveSlot = errorResult(e);
            captchaStatus.value = '';
            return;
        }
    }
    loopingReserve.value = true;
    loopCount.value.reserveSlot = 0;
    let consecutiveErrors = 0;
    try {
        while (loopingReserve.value && loopCount.value.reserveSlot < LOOP_MAX_ITERATIONS) {
            loopCount.value.reserveSlot++;
            await callReserveSlot(true);
            const r = results.value.reserveSlot;
            if (r && r.status_code >= 200 && r.status_code < 300) break;
            if (r && r.status_code === 0) {
                consecutiveErrors++;
                if (consecutiveErrors >= LOOP_MAX_CONSECUTIVE_ERRORS) break;
            } else {
                consecutiveErrors = 0;
            }
            if (!loopingReserve.value) break;
            await cancellableSleep('reserve', LOOP_GAP_MS, () => loopingReserve.value);
        }
    } finally {
        loopingReserve.value = false;
        abortLoopTimer('reserve');
    }
}

onBeforeUnmount(() => {
    loopingSignin.value = false;
    loopingVerify.value = false;
    loopingReserve.value = false;
    loopingPayment.value = false;
    loopingCallback.value = false;
    abortAllLoopTimers();
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
});

async function callInitiatePayment(reuseCaptcha = false) {
    if (!accessToken.value || !appointmentId.value) {
        alert('Need JWT + appointmentId — call Sign In and Booking Config first.');
        return;
    }
    if (reuseCaptcha && !lastPaymentCaptcha.value) { alert('No payment captcha cached — run Initiate Payment first.'); return; }
    busy.value.payment = true;
    try {
        const captcha = reuseCaptcha ? lastPaymentCaptcha.value : await fetchCaptcha('raw');
        lastPaymentCaptcha.value = captcha;
        const res = await axios.post('/api/api-tester/initiate-payment', {
            bypass_ip_id: bypassIpId.value,
            access_token: accessToken.value,
            appointment_id: appointmentId.value,
            gateway: gateway.value,
            payment_slot_id: paymentSlotId.value,
            captcha_token: captcha,
            url: stepPath.payment || undefined,
        });
        results.value.payment = res.data;
    } catch (e: unknown) {
        results.value.payment = errorResult(e);
    } finally {
        busy.value.payment = false;
        captchaStatus.value = '';
    }
}

async function loopPaymentUntilSuccess() {
    if (loopingPayment.value) {
        loopingPayment.value = false;
        abortLoopTimer('payment');
        return;
    }
    if (!accessToken.value || !appointmentId.value) {
        alert('Need JWT + appointmentId — call Sign In and Booking Config first.');
        return;
    }
    loopingPayment.value = true;
    loopCount.value.payment = 0;
    let consecutiveErrors = 0;
    try {
        while (loopingPayment.value && loopCount.value.payment < LOOP_MAX_ITERATIONS) {
            loopCount.value.payment++;
            await callInitiatePayment();
            const r = results.value.payment;
            if (r && r.status_code >= 200 && r.status_code < 300) break;
            if (r && r.status_code === 0) {
                consecutiveErrors++;
                if (consecutiveErrors >= LOOP_MAX_CONSECUTIVE_ERRORS) break;
            } else {
                consecutiveErrors = 0;
            }
            if (!loopingPayment.value) break;
            await cancellableSleep('payment', LOOP_GAP_MS, () => loopingPayment.value);
        }
    } finally {
        loopingPayment.value = false;
        abortLoopTimer('payment');
    }
}

async function callPaymentCallback() {
    if (!callbackUrlInput.value.trim()) { alert('Paste the post-payment redirect/callback URL first.'); return; }
    busy.value.paymentCallback = true;
    try {
        const res = await axios.post('/api/api-tester/payment-callback', {
            bypass_ip_id: bypassIpId.value,
            callback_url: callbackUrlInput.value.trim(),
        });
        results.value.paymentCallback = res.data;
    } catch (e: unknown) {
        results.value.paymentCallback = errorResult(e);
    } finally {
        busy.value.paymentCallback = false;
    }
}

async function loopPaymentCallbackUntilSuccess() {
    if (loopingCallback.value) {
        loopingCallback.value = false;
        abortLoopTimer('callback');
        return;
    }
    if (!callbackUrlInput.value.trim()) { alert('Paste the post-payment redirect/callback URL first.'); return; }
    loopingCallback.value = true;
    loopCount.value.paymentCallback = 0;
    let consecutiveErrors = 0;
    try {
        while (loopingCallback.value && loopCount.value.paymentCallback < LOOP_MAX_ITERATIONS) {
            loopCount.value.paymentCallback++;
            await callPaymentCallback();
            const r = results.value.paymentCallback;
            // 302 (or any 2xx/3xx) means the gateway accepted the callback = success
            if (r && r.status_code >= 200 && r.status_code < 400) break;
            if (r && r.status_code === 0) {
                consecutiveErrors++;
                if (consecutiveErrors >= LOOP_MAX_CONSECUTIVE_ERRORS) break;
            } else {
                consecutiveErrors = 0;
            }
            if (!loopingCallback.value) break;
            await cancellableSleep('callback', LOOP_GAP_MS, () => loopingCallback.value);
        }
    } finally {
        loopingCallback.value = false;
        abortLoopTimer('callback');
    }
}

async function downloadInvoice(reuseCaptcha = false) {
    if (!txrId.value.trim()) { alert('Enter a txrId first.'); return; }
    if (reuseCaptcha && !lastInvoiceCaptcha.value) { alert('No invoice captcha cached — run Download Invoice first.'); return; }
    downloadingInvoice.value = true;
    try {
        const captcha = reuseCaptcha ? lastInvoiceCaptcha.value : await fetchCaptcha('raw');
        lastInvoiceCaptcha.value = captcha;
        const params: Record<string, string | number> = { txrId: txrId.value.trim() };
        const headers: Record<string, string> = { 'X-Captcha-Token': captcha };
        if (accessToken.value) headers['X-Invoice-Token'] = accessToken.value;
        const res = await axios.get('/api/payment-links/invoice', { params, headers, responseType: 'blob' });
        const url = URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = `invoice-${txrId.value.trim()}.pdf`;
        a.click();
        URL.revokeObjectURL(url);
    } catch (e: unknown) {
        let msg = 'Failed to download invoice';
        if (axios.isAxiosError(e)) {
            const data = e.response?.data;
            // responseType:'blob' means error JSON arrives as a Blob — read it back.
            if (data instanceof Blob) {
                try { msg = (JSON.parse(await data.text()) as { message?: string }).message ?? msg; } catch { /* keep default */ }
            } else {
                msg = (data as { message?: string })?.message ?? e.message ?? msg;
            }
        }
        alert(msg);
    } finally {
        downloadingInvoice.value = false;
        captchaStatus.value = '';
    }
}

function errorResult(e: unknown): IvacResult {
    let message = 'Unknown error';
    if (axios.isAxiosError(e)) {
        message = ((e.response?.data as { message?: string })?.message ?? e.message ?? 'Request failed') as string;
        if (e.response?.data && typeof e.response.data === 'object') {
            return {
                method: 'POST', url: e.config?.url ?? '', bypass_ip: null,
                status_code: e.response?.status ?? 0,
                body: e.response.data, raw: JSON.stringify(e.response.data),
                duration_ms: 0, error: message,
            };
        }
    } else if (e instanceof Error) {
        message = e.message;
    }
    return { method: '', url: '', bypass_ip: null, status_code: 0, body: null, raw: '', duration_ms: 0, error: message };
}

// Endpoint box rendered under a Step's desc — the base URL sits to its left as a read-only prefix
// and the input carries the path only (v-model onto stepPath[stepKey]), with a reset-to-live-endpoint
// button that only appears once the path has been edited away from the live one (see defaultPath).
const PathField = defineComponent({
    props: { stepKey: { type: String, required: true } },
    setup(props) {
        return () => h('div', { class: 'flex items-center gap-1.5' }, [
            h('div', { class: 'flex min-w-0 flex-1 items-center rounded-md border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 focus-within:ring-2 focus-within:ring-indigo-500/30' }, [
                h('span', {
                    class: 'hidden sm:block shrink-0 border-r border-zinc-200 dark:border-zinc-700 px-2 py-1.5 font-mono text-[11px] text-zinc-400 dark:text-zinc-500 select-all',
                    title: ivacBaseUrl.value,
                }, ivacBaseUrl.value),
                h('input', {
                    value: stepPath[props.stepKey] ?? '',
                    onInput: (e: Event) => { stepPath[props.stepKey] = (e.target as HTMLInputElement).value; },
                    type: 'text',
                    spellcheck: 'false',
                    placeholder: '/endpoint/path',
                    // Password managers (LastPass/1Password/Bitwarden/Dashlane) sniff plain text inputs
                    // for login-like fields and clobber this with saved credentials — these are the
                    // vendor-recognized opt-out attributes, not a real autocomplete value.
                    autocomplete: 'off',
                    'data-lpignore': 'true',
                    'data-1p-ignore': 'true',
                    'data-bwignore': 'true',
                    'data-form-type': 'other',
                    class: 'w-full min-w-0 bg-transparent px-2.5 py-1.5 font-mono text-[11px] text-zinc-600 dark:text-zinc-300 outline-none',
                }),
            ]),
            stepPath[props.stepKey] !== defaultPath(props.stepKey)
                ? h('button', {
                    type: 'button',
                    title: 'Reset to the live endpoint',
                    onClick: () => { stepPath[props.stepKey] = defaultPath(props.stepKey); },
                    class: 'shrink-0 rounded-md border border-zinc-200 dark:border-zinc-700 p-1.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors',
                }, [h(Undo2, { class: 'h-3.5 w-3.5' })])
                : null,
        ]);
    },
});

const Step = defineComponent({
    props: { title: { type: String, required: true }, desc: { type: String, default: '' }, urlKey: { type: String, default: '' } },
    setup(props, { slots }) {
        return () => h('div', { class: 'rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-3' }, [
            // Title and actions always stack — a side-by-side flex row would sit them next to each
            // other whenever the actions were narrow enough to fit, which made the row structure
            // differ from step to step (e.g. Verify OTP vs. Sign In) depending on button count.
            h('div', { class: 'flex flex-col gap-2' }, [
                h('h2', { class: 'text-sm font-semibold' }, props.title),
                // Steps with a URL box show the description as its hint (below), not here —
                // it describes the request the box is about to fire, so it reads better there.
                !props.urlKey && props.desc ? h('p', { class: 'text-xs text-muted-foreground -mt-1' }, props.desc) : null,
                h('div', { class: 'flex items-center gap-1.5 sm:gap-2 flex-wrap' }, slots.actions?.()),
            ]),
            // Full-width row of its own — squeezing this into the title column next to the actions
            // column (as before) truncated it on steps with wide action bars like Reserve Slot.
            props.urlKey ? h('div', { class: 'space-y-1' }, [
                h(PathField, { stepKey: props.urlKey }),
                props.desc ? h('p', { class: 'text-xs text-muted-foreground' }, props.desc) : null,
            ]) : null,
            slots.default?.(),
        ]);
    },
});

const Result = defineComponent({
    props: { result: { type: Object as PropType<IvacResult | null>, default: null } },
    setup(props) {
        return () => {
            if (!props.result) return null;
            const r = props.result;
            const code = r.status_code;
            const badge = code >= 200 && code < 300
                ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                : code >= 300 && code < 400
                    ? 'bg-blue-500/15 text-blue-700 dark:text-blue-300'
                    : code >= 400 && code < 500
                        ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300'
                        : 'bg-red-500/15 text-red-700 dark:text-red-300';
            return h('div', { class: 'space-y-2' }, [
                h('div', { class: 'flex items-center gap-2 text-xs flex-wrap' }, [
                    h('span', { class: `inline-flex items-center rounded px-2 py-0.5 font-mono ${badge}` }, `${r.method || '-'} ${code || '?'}`),
                    h('span', { class: 'text-muted-foreground break-all' }, r.url),
                    r.bypass_ip ? h('span', { class: 'rounded bg-blue-500/15 text-blue-700 dark:text-blue-300 px-1.5 py-0.5' }, `via ${r.bypass_ip}`) : null,
                    h('span', { class: 'text-muted-foreground ml-auto' }, `${r.duration_ms}ms`),
                ]),
                r.location ? h('div', { class: 'text-xs break-all' }, [
                    h('span', { class: 'text-muted-foreground' }, 'Location: '),
                    h('span', { class: 'font-mono text-blue-700 dark:text-blue-300' }, r.location),
                ]) : null,
                r.error ? h('div', { class: 'text-xs text-red-600 dark:text-red-400' }, r.error) : null,
                h('pre', { class: 'rounded bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 p-2 text-[11px] overflow-auto max-h-72 whitespace-pre-wrap break-all' },
                    JSON.stringify(r.body, null, 2)),
            ]);
        };
    },
});

interface PdfOverview {
    applicationId?: string | null;
    commissionName?: string | null;
    dob?: string | null;
    email?: string | null;
    fullName?: string | null;
    ivacCenter?: string | null;
    nidOrBr?: string | null;
    passport?: string | null;
    phone?: string | null;
    primary?: boolean;
    visaType?: string | null;
}

const PdfUploadResultCard = defineComponent({
    props: { result: { type: Object as PropType<IvacResult | null>, default: null } },
    setup(props) {
        return () => {
            if (!props.result) return null;
            const r = props.result;
            const code = r.status_code;
            const badge = code >= 200 && code < 300
                ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                : 'bg-red-500/15 text-red-700 dark:text-red-300';

            const body = r.body as { data?: { overview?: PdfOverview; error?: string[] } } | null;
            const overview: PdfOverview | null = body?.data?.overview ?? null;
            const apiErrors: string[] = body?.data?.error ?? [];

            const metaRow = h('div', { class: 'flex items-center gap-2 text-xs flex-wrap' }, [
                h('span', { class: `inline-flex items-center rounded px-2 py-0.5 font-mono ${badge}` }, `POST ${code || '?'}`),
                h('span', { class: 'text-muted-foreground break-all' }, r.url),
                r.bypass_ip ? h('span', { class: 'rounded bg-blue-500/15 text-blue-700 dark:text-blue-300 px-1.5 py-0.5' }, `via ${r.bypass_ip}`) : null,
                h('span', { class: 'text-muted-foreground ml-auto' }, `${r.duration_ms}ms`),
            ]);

            const children: ReturnType<typeof h>[] = [metaRow];

            if (r.error) {
                children.push(h('div', { class: 'text-xs text-red-600 dark:text-red-400' }, r.error));
            }

            if (overview) {
                const fields: [string, string][] = [
                    ['Application ID', overview.applicationId ?? '—'],
                    ['Full Name', overview.fullName ?? '—'],
                    ['Passport #', overview.passport ?? '—'],
                    ['Phone', overview.phone ?? '—'],
                    ['Email', overview.email ?? '—'],
                    ['Date of Birth', overview.dob ?? '—'],
                    ['Visa Type', overview.visaType ?? '—'],
                    ['Commission', overview.commissionName ?? '—'],
                    ['NID / Birth Reg', overview.nidOrBr ?? '—'],
                    ['Primary', overview.primary ? '✓ Yes' : '✗ No'],
                ];

                const fieldRows = fields.map(([label, value]) =>
                    h('div', { class: 'flex border-t border-zinc-100 dark:border-zinc-800 first:border-t-0' }, [
                        h('span', { class: 'w-36 shrink-0 px-3 py-1.5 text-xs text-muted-foreground' }, label),
                        h('span', { class: 'flex-1 px-3 py-1.5 text-xs font-medium break-all' }, value),
                    ])
                );

                const errorSection = apiErrors.length > 0
                    ? h('div', { class: 'border-t border-zinc-200 dark:border-zinc-800 bg-red-500/5 px-3 py-2 space-y-1' }, [
                        h('p', { class: 'text-xs font-semibold text-red-600 dark:text-red-400 mb-1' }, 'Validation Errors'),
                        ...apiErrors.map((err) =>
                            h('p', { class: 'text-xs text-red-600/80 dark:text-red-400/80' }, `• ${err}`)
                        ),
                      ])
                    : h('div', { class: 'border-t border-zinc-200 dark:border-zinc-800 bg-emerald-500/5 px-3 py-2' },
                        h('p', { class: 'text-xs font-medium text-emerald-700 dark:text-emerald-400' }, '✓ No errors — document matches account')
                      );

                children.push(
                    h('div', { class: 'rounded border border-zinc-200 dark:border-zinc-800 overflow-hidden' }, [
                        h('div', { class: 'bg-zinc-50 dark:bg-zinc-950 px-3 py-2 text-[10px] font-semibold text-muted-foreground uppercase tracking-wide' }, 'Document Overview'),
                        ...fieldRows,
                        errorSection,
                    ])
                );
            } else {
                children.push(
                    h('pre', { class: 'rounded bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 p-2 text-[11px] overflow-auto max-h-72 whitespace-pre-wrap break-all' },
                        JSON.stringify(r.body, null, 2))
                );
            }

            return h('div', { class: 'space-y-2' }, children);
        };
    },
});
</script>

<template>
    <Head title="API Tester" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-3 sm:p-5 flex flex-col lg:flex-row gap-4 lg:gap-5 items-start">
            <div class="w-full lg:flex-1 min-w-0 space-y-4">
            <div class="flex items-start justify-between gap-2 flex-wrap">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-teal-400 to-cyan-600 shadow-sm shadow-teal-500/30">
                        <TerminalSquare class="h-5 w-5 text-white" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">API Tester</h1>
                        <p class="text-muted-foreground mt-0.5 text-sm">Manually fire each IVAC API call against api.ivacbd.com.</p>
                    </div>
                </div>
                <div v-if="captchaStatus" class="flex items-center gap-2 text-xs text-muted-foreground">
                    <Loader2 class="size-3 animate-spin" />
                    {{ captchaStatus }}
                </div>
            </div>

            <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 p-4 bg-white dark:bg-zinc-900 space-y-3">
                <div class="grid grid-cols-1 gap-3">
                    <div class="flex flex-col gap-1.5 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="font-medium">Bypass IP</span>
                            <div v-if="useBypass" class="flex items-center gap-1.5 text-[10px] text-black font-semibold bg-emerald-100 px-2 py-0.5 rounded-full">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span>
                                Bypass IP
                            </div>
                            <div v-else class="flex items-center gap-1.5 text-[10px] text-black font-semibold bg-sky-100 px-2 py-0.5 rounded-full">
                                <span class="w-1.5 h-1.5 rounded-full bg-sky-500 inline-block"></span>
                                Direct (Server IP)
                            </div>
                        </div>
                        <div class="flex items-center gap-1 p-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 w-fit">
                            <button
                                @click="useBypass = false; bypassIpId = null; localStorage.setItem('apiTester.useBypass', 'false')"
                                class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
                                :class="!useBypass
                                    ? 'bg-white dark:bg-zinc-900 text-sky-700 dark:text-sky-300 shadow-sm'
                                    : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                            >
                                <Server class="h-3.5 w-3.5" />
                                Direct
                            </button>
                            <button
                                @click="useBypass = true; localStorage.setItem('apiTester.useBypass', 'true'); if (!bypassIpId) { const def = bypassIps.find(b => b.is_default); if (def) bypassIpId = def.id; }"
                                class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-colors"
                                :class="useBypass
                                    ? 'bg-white dark:bg-zinc-900 text-amber-700 dark:text-amber-300 shadow-sm'
                                    : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                            >
                                <Globe class="h-3.5 w-3.5" />
                                Bypass IP
                            </button>
                        </div>
                        <select
                            v-if="useBypass"
                            v-model="bypassIpId"
                            class="w-full rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-2 text-sm"
                        >
                            <option :value="null">— select bypass IP —</option>
                            <option v-for="b in bypassIps" :key="b.id" :value="b.id">
                                {{ b.label }} — {{ b.ip }}{{ b.is_default ? ' (default)' : '' }}
                            </option>
                        </select>
                        <p v-else class="text-[11px] text-sky-600 dark:text-sky-400 bg-sky-50 dark:bg-sky-950/30 rounded-md px-3 py-2 border border-sky-200 dark:border-sky-900">
                            Using this server's IP directly via cloudscraper — no bypass needed.
                        </p>
                    </div>
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="font-medium">Account</span>
                        <select v-model="accountId" class="w-full rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-2 text-sm">
                            <option :value="null">— select account —</option>
                            <option v-for="a in accounts" :key="a.id" :value="a.id">
                                {{ a.phone }}{{ a.tag ? ` [${a.tag}]` : '' }} #{{ a.id }} — {{ a.email }} ({{ fmtExpiresIn(a.jwt_expires_at) }})
                            </option>
                        </select>
                    </label>
                </div>
            </div>

            <!-- Protocol Constants Panel — IVAC endpoint paths + rotating headers, editable in place -->
            <div v-if="canManageEndpoints" class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
                <button
                    type="button"
                    @click="toggleEndpointsPanel"
                    class="w-full flex items-center justify-between gap-3 p-4 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                >
                    <div class="flex items-center gap-2.5">
                        <Waypoints class="h-4 w-4 text-indigo-600 dark:text-indigo-400 shrink-0" />
                        <div>
                            <h2 class="text-sm font-semibold">Protocol Constants — Endpoints &amp; Headers</h2>
                            <p class="text-xs text-muted-foreground mt-0.5">
                                Every path + header this tester's calls use, pulled live from portal settings (same source the bot reads).
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span v-if="endpointsDirty" class="inline-flex items-center gap-1 text-[11px] text-amber-600 dark:text-amber-400">
                            <PencilLine class="h-3 w-3" /> Unsaved
                        </span>
                        <component :is="showEndpointsPanel ? ChevronUp : ChevronDown" class="h-4 w-4 text-zinc-400" />
                    </div>
                </button>
                <div v-if="showEndpointsPanel" class="border-t border-zinc-100 dark:border-zinc-800 p-4 space-y-3">
                    <div v-if="endpointsLoading && !endpointsLoaded" class="flex items-center justify-center py-8 text-sm text-zinc-400">
                        <Loader2 class="mr-2 h-4 w-4 animate-spin" /> Loading…
                    </div>
                    <p v-else-if="endpointsError && !endpointsLoaded" class="text-sm text-red-600 dark:text-red-400">{{ endpointsError }}</p>
                    <template v-else>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div v-for="key in endpointPathKeys" :key="key" class="space-y-1">
                                <div class="flex items-center gap-1.5">
                                    <label class="text-xs font-medium">{{ endpointsMeta[key].label }}</label>
                                    <span
                                        :class="endpointsMeta[key].sync === 'auto'
                                            ? 'rounded bg-emerald-100 px-1 py-px text-[9px] font-semibold text-black'
                                            : 'rounded bg-amber-100 px-1 py-px text-[9px] font-semibold text-black'"
                                    >{{ endpointsMeta[key].sync }}</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <input
                                        v-model="endpointsForm[key]"
                                        type="text"
                                        spellcheck="false"
                                        :class="[
                                            'w-full rounded-md border bg-zinc-50 dark:bg-zinc-800 px-2.5 py-1.5 font-mono text-xs outline-none focus:ring-2',
                                            endpointFieldError(key) ? 'border-red-400 focus:ring-red-500/30' : 'border-zinc-200 dark:border-zinc-700 focus:ring-indigo-500/30',
                                        ]"
                                    />
                                    <button
                                        v-if="endpointsDefaults[key] !== undefined && endpointsForm[key] !== endpointsDefaults[key]"
                                        type="button"
                                        @click="endpointsForm[key] = endpointsDefaults[key]"
                                        title="Reset this field to its default"
                                        class="shrink-0 rounded-md border border-zinc-200 dark:border-zinc-700 p-1.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors"
                                    >
                                        <Undo2 class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <p v-if="endpointFieldError(key)" class="text-[10px] text-red-600 dark:text-red-400">{{ endpointFieldError(key) }}</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                            <div v-for="key in endpointHeaderKeys" :key="key" class="space-y-1">
                                <div class="flex items-center gap-1.5">
                                    <label class="text-xs font-medium">{{ endpointsMeta[key].label }}</label>
                                    <span class="rounded bg-zinc-100 px-1 py-px font-mono text-[9px] text-black font-semibold">{{ endpointsMeta[key].header }}</span>
                                    <span
                                        :class="endpointsMeta[key].sync === 'auto'
                                            ? 'rounded bg-emerald-100 px-1 py-px text-[9px] font-semibold text-black'
                                            : 'rounded bg-amber-100 px-1 py-px text-[9px] font-semibold text-black'"
                                    >{{ endpointsMeta[key].sync }}</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <input
                                        v-model="endpointsForm[key]"
                                        type="text"
                                        spellcheck="false"
                                        :class="[
                                            'w-full rounded-md border bg-zinc-50 dark:bg-zinc-800 px-2.5 py-1.5 font-mono text-xs outline-none focus:ring-2',
                                            endpointFieldError(key) ? 'border-red-400 focus:ring-red-500/30' : 'border-zinc-200 dark:border-zinc-700 focus:ring-indigo-500/30',
                                        ]"
                                    />
                                    <button
                                        v-if="endpointsDefaults[key] !== undefined && endpointsForm[key] !== endpointsDefaults[key]"
                                        type="button"
                                        @click="endpointsForm[key] = endpointsDefaults[key]"
                                        title="Reset this field to its default"
                                        class="shrink-0 rounded-md border border-zinc-200 dark:border-zinc-700 p-1.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors"
                                    >
                                        <Undo2 class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                                <p v-if="endpointFieldError(key)" class="text-[10px] text-red-600 dark:text-red-400">{{ endpointFieldError(key) }}</p>
                            </div>
                        </div>
                        <p v-if="endpointsError" class="text-xs text-red-600 dark:text-red-400">{{ endpointsError }}</p>
                        <div class="flex items-center gap-2 pt-2">
                            <Button size="sm" :disabled="endpointsSaving || !endpointsDirty || endpointsHasErrors" @click="saveEndpoints">
                                <Loader2 v-if="endpointsSaving" class="mr-1.5 size-3 animate-spin" />
                                <Save v-else class="mr-1.5 size-3" />
                                Save
                            </Button>
                            <Button size="sm" variant="outline" :disabled="endpointsSaving" @click="resetEndpointsToDefaults">
                                <RotateCcw class="mr-1.5 size-3" />
                                Reset to Defaults
                            </Button>
                            <a href="/ivac-endpoints" class="ml-auto text-xs text-indigo-600 dark:text-indigo-400 hover:underline">Full editor (request constants too) →</a>
                        </div>
                    </template>
                </div>
            </div>

            <!-- JWT Token Panel -->
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-3">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <h2 class="text-sm font-semibold">JWT Token</h2>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            Loaded from DB for the selected account. Edit &amp; save manually, or sign in to refresh.
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-muted-foreground">Expires in:</span>
                        <span class="text-sm font-mono font-semibold tabular-nums" :class="countdownColor">{{ countdownLabel }}</span>
                    </div>
                </div>
                <textarea
                    v-model="jwtInput"
                    rows="3"
                    placeholder="Select an account to load its JWT, or paste a token here…"
                    :disabled="!accountId"
                    class="w-full rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-2 text-[11px] font-mono resize-none disabled:opacity-50"
                />
                <div class="flex gap-2 flex-wrap">
                    <Button size="sm" :disabled="!accountId || !jwtInput.trim() || savingToken" @click="saveManualToken">
                        <Loader2 v-if="savingToken" class="mr-1.5 size-3 animate-spin" />
                        Save to DB
                    </Button>
                    <Button size="sm" variant="outline" :disabled="!accessToken || jwtInput === accessToken" @click="jwtInput = accessToken">
                        Reset
                    </Button>
                </div>
                <div class="flex items-center gap-2 pt-1 border-t border-zinc-100 dark:border-zinc-800">
                    <span class="text-xs text-muted-foreground shrink-0">Sign-in requestId:</span>
                    <code class="flex-1 truncate rounded bg-zinc-100 dark:bg-zinc-800 px-2 py-1 text-[11px] font-mono" :title="signinRequestId || undefined">{{ signinRequestId || '—' }}</code>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="flex gap-0.5 p-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 w-fit">
                <button
                    @click="activeTab = 'flow'; localStorage.setItem('apiTester.tab', 'flow')"
                    class="flex items-center gap-2 px-4 py-2 rounded-md text-xs font-medium transition-colors"
                    :class="activeTab === 'flow'
                        ? 'bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white shadow-sm'
                        : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                >
                    <Zap class="h-3.5 w-3.5" />
                    Booking Flow
                </button>
                <button
                    @click="activeTab = 'documents'; localStorage.setItem('apiTester.tab', 'documents')"
                    class="flex items-center gap-2 px-4 py-2 rounded-md text-xs font-medium transition-colors"
                    :class="activeTab === 'documents'
                        ? 'bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white shadow-sm'
                        : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                >
                    <FileText class="h-3.5 w-3.5" />
                    Documents
                </button>
                <button
                    @click="activeTab = 'signup'; localStorage.setItem('apiTester.tab', 'signup')"
                    class="flex items-center gap-2 px-4 py-2 rounded-md text-xs font-medium transition-colors"
                    :class="activeTab === 'signup'
                        ? 'bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white shadow-sm'
                        : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                >
                    <UserPlus class="h-3.5 w-3.5" />
                    Sign Up
                </button>
            </div>

            <!-- Booking Flow Tab -->
            <div v-show="activeTab === 'flow'" class="space-y-4">
                <Step title="1. Sign In" :desc="`POST ${livePath('signin')} — fetches turnstile captcha, returns JWT + requestId.`" url-key="signin">
                    <template #actions>
                        <label class="flex items-center gap-1.5 cursor-pointer select-none text-xs text-muted-foreground">
                            <input type="checkbox" v-model="useManualSigninCaptcha" :disabled="skipSigninCaptcha" class="rounded" />
                            Manual captcha
                        </label>
                        <label class="flex items-center gap-1.5 cursor-pointer select-none text-xs text-orange-600 dark:text-orange-400">
                            <input type="checkbox" v-model="skipSigninCaptcha" @change="skipSigninCaptcha && (useManualSigninCaptcha = false)" class="rounded" />
                            No captcha
                        </label>
                        <label class="flex items-center gap-1.5 cursor-pointer select-none text-xs text-violet-600 dark:text-violet-400">
                            <input type="checkbox" v-model="useSpoofXff" class="rounded" />
                            XFF spoof
                        </label>
                        <Button size="sm" :disabled="!accountId || busy.signin" @click="callSignin(false)">
                            <Loader2 v-if="busy.signin" class="mr-2 size-3 animate-spin" />
                            Sign In
                        </Button>
                        <Button size="sm" variant="outline" :disabled="!accountId || busy.signin || (!lastSigninCaptcha && !useManualSigninCaptcha && !skipSigninCaptcha)" @click="callSignin(true)">
                            <Loader2 v-if="busy.signin" class="mr-2 size-3 animate-spin" />
                            Retry (reuse captcha)
                        </Button>
                        <Button
                            size="sm"
                            :variant="loopingSignin ? 'destructive' : 'outline'"
                            :disabled="!accountId || (busy.signin && !loopingSignin)"
                            @click="loopSigninUntilSuccess"
                        >
                            <Loader2 v-if="loopingSignin" class="mr-2 size-3 animate-spin" />
                            {{ loopingSignin ? `Stop (${loopCount.signin})` : 'Retry until success' }}
                        </Button>
                        <Button size="sm" variant="outline" :disabled="!accountId || busy.signin" class="border-zinc-200 dark:border-zinc-700 text-amber-600 dark:text-amber-400 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="callSigninRaw(false)">
                            <Loader2 v-if="busy.signin" class="mr-2 size-3 animate-spin" />
                            Sign In (raw)
                        </Button>
                        <Button size="sm" variant="outline" :disabled="!accountId || busy.signin || !lastRawCaptcha" class="border-zinc-200 dark:border-zinc-700 text-amber-600 dark:text-amber-400 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="callSigninRaw(true)">
                            Retry raw
                        </Button>
                    </template>
                    <div v-if="useManualSigninCaptcha" class="mb-2">
                        <textarea
                            v-model="manualSigninCaptchaToken"
                            rows="2"
                            placeholder="Paste raw Turnstile token here…"
                            class="w-full rounded-md border border-amber-300 dark:border-amber-700 bg-amber-50/50 dark:bg-amber-900/10 px-3 py-2 text-[11px] font-mono resize-none"
                        />
                        <p class="text-[10px] text-amber-600 dark:text-amber-400 mt-1">Manual mode — portal transform will NOT be applied. Token sent as-is.</p>
                    </div>
                    <div class="mb-2 flex items-center gap-2 flex-wrap">
                        <label class="flex items-center gap-1.5 cursor-pointer select-none text-xs text-muted-foreground">
                            <input type="checkbox" v-model="useSpoofXff" class="rounded" />
                            Spoof X-Forwarded-For
                        </label>
                        <template v-if="useSpoofXff">
                            <input
                                v-model="spoofedIp"
                                type="text"
                                placeholder="e.g. 203.0.113.45"
                                class="w-40 rounded-md border border-violet-300 dark:border-violet-700 bg-violet-50/50 dark:bg-violet-900/10 px-2 py-1 text-xs font-mono"
                            />
                            <button
                                type="button"
                                @click="spoofedIp = generateRandomIp()"
                                class="rounded-md border border-violet-300 dark:border-violet-700 bg-violet-50 dark:bg-violet-900/20 px-2 py-1 text-xs text-violet-700 dark:text-violet-400 hover:bg-violet-100 dark:hover:bg-violet-900/40"
                            >
                                Random
                            </button>
                            <span class="text-[10px] text-violet-600 dark:text-violet-400">Sends X-Forwarded-For + X-Real-IP with this IP</span>
                        </template>
                    </div>
                    <Result :result="results.signin" />
                </Step>

                <Step title="2. Send OTP (forgot-password)" :desc="`POST ${livePath('sendOtp')} — triggers OTP delivery before the window (pre-fetch flow).`" url-key="sendOtp">
                    <template #actions>
                        <select v-model="otpChannel" class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-2 py-1.5 text-sm">
                            <option value="phone">PHONE</option>
                            <option value="email">EMAIL</option>
                        </select>
                        <Button size="sm" :disabled="!accountId || busy.sendOtp" @click="callSendOtp">
                            <Loader2 v-if="busy.sendOtp" class="mr-2 size-3 animate-spin" />
                            Send OTP
                        </Button>
                    </template>
                    <div class="flex items-center gap-2 text-xs text-muted-foreground mb-2 flex-wrap">
                        <span>forgot-password requestId:</span>
                        <code class="rounded bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5">{{ fpRequestId || '—' }}</code>
                        <span class="ml-3">sign-in requestId:</span>
                        <code class="rounded bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5">{{ signinRequestId || '—' }}</code>
                    </div>
                    <Result :result="results.sendOtp" />
                </Step>

                <Step title="3. Verify OTP" :desc="`POST ${livePath('verifyOtp')} — pick which requestId to use.`" url-key="verifyOtp">
                    <template #actions>
                        <select v-model="verifyRequestIdSource" class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-2 py-1.5 text-sm">
                            <option value="fp">forgot-password</option>
                            <option value="signin">sign-in</option>
                            <option value="custom">custom</option>
                        </select>
                        <input v-model="otpCode" type="text" inputmode="numeric" maxlength="8" placeholder="OTP code"
                            class="w-32 rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-1.5 text-sm tracking-widest" />
                        <Button size="sm" variant="outline" :disabled="!accountId || busy.fetchOtp" @click="callFetchOtp">
                            <Loader2 v-if="busy.fetchOtp" class="mr-2 size-3 animate-spin" />
                            Fetch OTP
                        </Button>
                        <Button size="sm" :disabled="!accountId || !otpCode || busy.verifyOtp" @click="callVerifyOtp">
                            <Loader2 v-if="busy.verifyOtp && !loopingVerify" class="mr-2 size-3 animate-spin" />
                            Verify OTP
                        </Button>
                        <Button
                            size="sm"
                            :variant="loopingVerify ? 'destructive' : 'outline'"
                            :disabled="!accountId || !otpCode || (busy.verifyOtp && !loopingVerify)"
                            @click="loopVerifyOtpUntilSuccess"
                        >
                            <Loader2 v-if="loopingVerify" class="mr-2 size-3 animate-spin" />
                            {{ loopingVerify ? `Stop (${loopCount.verifyOtp})` : 'Verify until success' }}
                        </Button>
                    </template>
                    <div v-if="verifyRequestIdSource === 'custom'" class="mb-2">
                        <input v-model="customRequestId" type="text" placeholder="paste requestId here"
                            class="w-full rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-1.5 text-sm font-mono" />
                    </div>
                    <div v-else class="text-xs text-muted-foreground mb-2">
                        Using:
                        <code class="rounded bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 break-all">{{
                            verifyRequestIdSource === 'fp' ? (fpRequestId || '— none —') : (signinRequestId || '— none —')
                        }}</code>
                    </div>
                    <Result :result="results.verifyOtp" />
                </Step>

                <Step title="3b. Create Appointment" desc="POST /appointment (empty body) — creates the appointment record the file service needs before PDF upload. Idempotent. Requires JWT." url-key="createAppointment">
                    <template #actions>
                        <Button size="sm" :disabled="!accessToken || busy.createAppointment" @click="callCreateAppointment">
                            <Loader2 v-if="busy.createAppointment" class="mr-2 size-3 animate-spin" />
                            Create Appointment
                        </Button>
                    </template>
                    <div v-if="appointmentId" class="text-xs text-muted-foreground mb-2">
                        appointmentId: <code class="rounded bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5">{{ appointmentId }}</code>
                    </div>
                    <Result :result="results.createAppointment" />
                </Step>

                <Step title="4. Get Booking Config" :desc="`GET ${livePath('getBookingConfig')} — returns appointmentId. Requires JWT.`" url-key="getBookingConfig">
                    <template #actions>
                        <Button size="sm" :disabled="!accessToken || busy.bookingConfig" @click="callBookingConfig">
                            <Loader2 v-if="busy.bookingConfig" class="mr-2 size-3 animate-spin" />
                            Get Booking Config
                        </Button>
                    </template>
                    <div v-if="appointmentId" class="text-xs text-muted-foreground mb-2">
                        appointmentId: <code class="rounded bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5">{{ appointmentId }}</code>
                    </div>
                    <Result :result="results.bookingConfig" />
                </Step>

                <Step title="5. Reserve Slot" :desc="`POST ${livePath('reserveSlot')} — slot ID from portal settings; fetches encrypted turnstile captcha. Requires JWT.`" url-key="reserveSlot">
                    <template #actions>
                        <input v-model="appointmentDate" type="date"
                            class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-1.5 text-sm" />
                        <Button size="sm" :disabled="!accessToken || busy.reserveSlot" @click="callReserveSlot(false)">
                            <Loader2 v-if="busy.reserveSlot" class="mr-2 size-3 animate-spin" />
                            Reserve Slot
                        </Button>
                        <Button size="sm" variant="outline" :disabled="!accessToken || busy.reserveSlot || !lastReserveCaptcha" @click="callReserveSlot(true)">
                            <Loader2 v-if="busy.reserveSlot" class="mr-2 size-3 animate-spin" />
                            Retry (reuse captcha)
                        </Button>
                        <Button
                            size="sm"
                            :variant="loopingReserve ? 'destructive' : 'outline'"
                            :disabled="!accessToken || (busy.reserveSlot && !loopingReserve)"
                            @click="loopReserveSlotUntilSuccess"
                        >
                            <Loader2 v-if="loopingReserve" class="mr-2 size-3 animate-spin" />
                            {{ loopingReserve ? `Stop (${loopCount.reserveSlot})` : 'Retry until success' }}
                        </Button>
                    </template>
                    <Result :result="results.reserveSlot" />
                </Step>

                <Step title="6. Initiate Payment" :desc="`POST ${livePath('payment')} — sends raw Turnstile token in x-token header; returns gateway redirect URL.`" url-key="payment">
                    <template #actions>
                        <input v-model="appointmentId" type="text" placeholder="appointmentId"
                            class="w-48 rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-1.5 text-sm" />
                        <select v-model="gateway" class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-2 py-1.5 text-sm">
                            <option value="dg-epay">dg-epay</option>
                            <option value="ssl">ssl</option>
                        </select>
                        <input v-if="gateway === 'dg-epay'" v-model="paymentSlotId" type="text" placeholder="payment slot UUID (from bundle)"
                            class="w-72 rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-1.5 text-sm font-mono" />
                        <Button size="sm" :disabled="!accessToken || !appointmentId || busy.payment" @click="callInitiatePayment(false)">
                            <Loader2 v-if="busy.payment" class="mr-2 size-3 animate-spin" />
                            Initiate Payment
                        </Button>
                        <Button size="sm" variant="outline" :disabled="!accessToken || !appointmentId || busy.payment || !lastPaymentCaptcha" @click="callInitiatePayment(true)">
                            Reuse captcha
                        </Button>
                        <Button
                            size="sm"
                            :variant="loopingPayment ? 'destructive' : 'outline'"
                            :disabled="!accessToken || !appointmentId || (busy.payment && !loopingPayment)"
                            @click="loopPaymentUntilSuccess"
                        >
                            <Loader2 v-if="loopingPayment" class="mr-2 size-3 animate-spin" />
                            {{ loopingPayment ? `Stop (${loopCount.payment})` : 'Retry until success' }}
                        </Button>
                    </template>
                    <Result :result="results.payment" />
                </Step>

                <Step title="7. Payment Callback">
                    <template #actions>
                        <Button size="sm" :disabled="busy.paymentCallback || !callbackUrlInput.trim()" @click="callPaymentCallback">
                            <Loader2 v-if="busy.paymentCallback" class="mr-2 size-3 animate-spin" />
                            Send Callback
                        </Button>
                        <Button
                            size="sm"
                            :variant="loopingCallback ? 'destructive' : 'outline'"
                            :disabled="(busy.paymentCallback && !loopingCallback) || !callbackUrlInput.trim()"
                            @click="loopPaymentCallbackUntilSuccess"
                        >
                            <Loader2 v-if="loopingCallback" class="mr-2 size-3 animate-spin" />
                            {{ loopingCallback ? `Stop (${loopCount.paymentCallback})` : 'Retry until success' }}
                        </Button>
                    </template>
                    <textarea
                        v-model="callbackUrlInput"
                        rows="3"
                        spellcheck="false"
                        placeholder="https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback?tran_id=…&data=…"
                        class="w-full rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent p-2 text-xs font-mono break-all focus:outline-none focus:ring-2 focus:ring-blue-500/60 focus:border-blue-500"
                    ></textarea>
                    <p class="text-xs text-muted-foreground mt-1">GET the post-payment redirect URL (no auth, no redirect-follow). A 302 → /payment/fail means success.</p>
                    <Result :result="results.paymentCallback" />
                </Step>

                <Step title="8. Download Invoice">
                    <template #actions>
                        <input
                            v-model="txrId"
                            type="text"
                            placeholder="txrId (auto-filled from callback URL)"
                            class="w-72 rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-1.5 text-sm font-mono"
                        />
                        <Button size="sm" :disabled="!txrId.trim() || downloadingInvoice" @click="downloadInvoice(false)">
                            <Loader2 v-if="downloadingInvoice" class="mr-2 size-3 animate-spin" />
                            <Download v-else class="mr-2 size-3" />
                            {{ downloadingInvoice ? 'Downloading…' : 'Download Invoice' }}
                        </Button>
                        <Button size="sm" variant="outline" :disabled="!txrId.trim() || downloadingInvoice || !lastInvoiceCaptcha" @click="downloadInvoice(true)">
                            Reuse captcha
                        </Button>
                    </template>
                    <p class="text-xs text-muted-foreground">GET /invoice/download?txrId=… — sends raw Turnstile token in x-token header; fetched server-side via cloudscraper + BD proxy (Cloudflare bypass). Uses the selected account's token.</p>
                </Step>

            </div>

            <!-- Documents Tab -->
            <div v-show="activeTab === 'documents'" class="space-y-4">
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
                    <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-950">
                        <h2 class="text-sm font-semibold">Document &amp; Booking Setup</h2>
                        <p class="text-xs text-muted-foreground mt-0.5">Upload passport PDF, validate documents, and assign mission/IVAC center. All operations require JWT.</p>
                    </div>

                    <!-- Edit PDF -->
                    <div class="p-4 space-y-3">
                        <div class="flex items-start justify-between gap-3 flex-wrap">
                            <div>
                                <p class="text-sm font-medium">Edit PDF</p>
                                <p class="text-xs text-muted-foreground mt-0.5">Replace Surname and Given Name fields in a visa-application PDF, then download the edited file.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button variant="outline" size="sm" :disabled="!editPdfFile" @click="clearEditPdf">Clear</Button>
                                <Button size="sm" :disabled="!accountId || !editPdfFile || editingPdf" @click="callEditPdf">
                                    <Loader2 v-if="editingPdf" class="mr-1.5 size-3 animate-spin" />
                                    Edit &amp; Download
                                </Button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div class="space-y-1">
                                <label class="text-xs text-muted-foreground">Surname</label>
                                <input v-model="editSurname" type="text" placeholder="e.g. HOSEN"
                                    class="w-full h-7 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent px-2 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-blue-500" />
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs text-muted-foreground">Given Name</label>
                                <input v-model="editGivenName" type="text" placeholder="e.g. MD JABED"
                                    class="w-full h-7 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent px-2 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-blue-500" />
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs text-muted-foreground">Passport No</label>
                                <input v-model="editPassportNo" type="text" placeholder="e.g. A06436613"
                                    class="w-full h-7 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent px-2 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-blue-500" />
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs text-muted-foreground">Phone</label>
                                <input v-model="editPhone" type="text" placeholder="e.g. 01518498583"
                                    class="w-full h-7 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent px-2 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-blue-500" />
                            </div>
                            <div class="col-span-2 space-y-1">
                                <label class="text-xs text-muted-foreground">Email</label>
                                <input v-model="editEmail" type="text" placeholder="e.g. user@gmail.com"
                                    class="w-full h-7 rounded border border-zinc-300 dark:border-zinc-700 bg-transparent px-2 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-blue-500" />
                            </div>
                        </div>

                        <label
                            class="flex flex-col items-center justify-center w-full h-24 rounded-lg border-2 border-dashed cursor-pointer transition-colors"
                            :class="editPdfFile
                                ? 'border-emerald-400 dark:border-emerald-600 bg-emerald-500/5'
                                : 'border-zinc-300 dark:border-zinc-700 hover:border-zinc-400 dark:hover:border-zinc-500'"
                            @dragover.prevent
                            @drop.prevent="onEditPdfDrop"
                        >
                            <input ref="editPdfInput" type="file" accept="application/pdf,.pdf" class="hidden" @change="onEditPdfSelect" />
                            <div v-if="editPdfFile" class="text-center">
                                <p class="text-sm font-medium text-emerald-700 dark:text-emerald-400">{{ editPdfFile.name }}</p>
                                <p class="text-xs text-muted-foreground mt-0.5">{{ (editPdfFile.size / 1024).toFixed(1) }} KB — click to replace</p>
                            </div>
                            <div v-else class="text-center pointer-events-none select-none">
                                <p class="text-sm text-muted-foreground">Drop a PDF here or <span class="text-blue-600 dark:text-blue-400 underline">browse</span></p>
                                <p class="text-xs text-muted-foreground mt-0.5">Max 20 MB</p>
                            </div>
                        </label>

                        <p v-if="editPdfError" class="text-xs text-red-500">{{ editPdfError }}</p>
                    </div>

                    <div class="border-t border-zinc-200 dark:border-zinc-800" />

                    <!-- Upload PDF -->
                    <div class="p-4 space-y-3">
                        <div class="flex items-start justify-between gap-3 flex-wrap">
                            <div>
                                <p class="text-sm font-medium">Upload PDF</p>
                                <p class="text-xs text-muted-foreground mt-0.5">POST {{ livePath('uploadFile') }} — upload the account's attached documents to IVAC (primary first, then the rest).</p>
                            </div>
                            <Button size="sm" :disabled="!accountId || !accessToken || accountPdfs.length === 0 || uploadingAllPdfs" @click="uploadAllAccountPdfs">
                                <Loader2 v-if="uploadingAllPdfs" class="mr-1.5 size-3 animate-spin" />
                                Upload to IVAC
                            </Button>
                        </div>

                        <p v-if="accountPdfs.length === 0" class="text-xs text-muted-foreground">No PDFs attached to this account — add them on the Accounts page.</p>

                        <div v-else class="space-y-2">
                            <div v-for="pdf in accountPdfs" :key="pdf.id" class="rounded-lg border border-zinc-200 dark:border-zinc-800 p-3 space-y-2">
                                <div class="flex items-center gap-2">
                                    <FileText class="size-4 text-muted-foreground shrink-0" />
                                    <span class="text-sm font-medium truncate">{{ pdf.name }}</span>
                                    <span v-if="pdf.is_primary" class="text-[10px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">Primary</span>
                                    <span v-else class="text-[10px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded bg-zinc-500/10 text-muted-foreground">Secondary</span>
                                    <span v-if="pdf.size" class="text-xs text-muted-foreground ml-auto whitespace-nowrap">{{ (pdf.size / 1024).toFixed(1) }} KB</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="text-xs"
                                        :class="{
                                            'text-muted-foreground': pdfState(pdf.id) === 'idle' || pdfState(pdf.id) === 'uploading',
                                            'text-emerald-600 dark:text-emerald-400': pdfState(pdf.id) === 'success',
                                            'text-red-500': pdfState(pdf.id) === 'failed',
                                        }"
                                    >
                                        <template v-if="pdfState(pdf.id) === 'uploading'">Uploading…</template>
                                        <template v-else-if="pdfState(pdf.id) === 'success'">Uploaded ✓</template>
                                        <template v-else-if="pdfState(pdf.id) === 'failed'">Failed</template>
                                        <template v-else>Not uploaded</template>
                                    </span>
                                    <Button
                                        size="sm"
                                        class="ml-auto bg-blue-600 text-white hover:bg-blue-700"
                                        :disabled="!accessToken || uploadingAllPdfs || pdfState(pdf.id) === 'uploading'"
                                        @click="uploadOneAccountPdf(pdf)"
                                    >
                                        <Loader2 v-if="pdfState(pdf.id) === 'uploading'" class="mr-1.5 size-3 animate-spin" />
                                        <Upload v-else class="mr-1.5 size-3" />
                                        Upload
                                    </Button>
                                </div>
                                <PdfUploadResultCard v-if="pdfUploadStatus[pdf.id]?.result" :result="pdfUploadStatus[pdf.id]!.result!" />
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-zinc-200 dark:border-zinc-800" />

                    <!-- File Overview -->
                    <div class="p-4 space-y-3">
                        <div class="flex items-start justify-between gap-3 flex-wrap">
                            <div>
                                <p class="text-sm font-medium">File Overview</p>
                                <p class="text-xs text-muted-foreground mt-0.5">POST /file/overview — list uploaded documents linked to the account.</p>
                            </div>
                            <Button size="sm" :disabled="!accessToken || loadingFileOverview" @click="callFileOverview">
                                <Loader2 v-if="loadingFileOverview" class="mr-1.5 size-3 animate-spin" />
                                Execute
                            </Button>
                        </div>
                        <Result v-if="fileOverviewResult" :result="fileOverviewResult" />
                    </div>

                    <div class="border-t border-zinc-200 dark:border-zinc-800" />

                    <!-- Set Booking Config -->
                    <div class="p-4 space-y-3">
                        <div class="flex items-start justify-between gap-3 flex-wrap">
                            <div>
                                <p class="text-sm font-medium">Set Booking Config</p>
                                <p class="text-xs text-muted-foreground mt-0.5">POST /appointment/appointment-booking-config — assign mission &amp; IVAC center.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <select v-model="selectedCity" class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-2 py-1.5 text-sm">
                                    <option v-for="city in Object.keys(CITY_CONFIG)" :key="city" :value="city">{{ city }}</option>
                                </select>
                                <Button size="sm" :disabled="!accessToken || settingBookingConfig" @click="callSetBookingConfig">
                                    <Loader2 v-if="settingBookingConfig" class="mr-1.5 size-3 animate-spin" />
                                    Execute
                                </Button>
                            </div>
                        </div>

                        <div v-if="bookingConfigPayload" class="rounded bg-zinc-50 dark:bg-zinc-950 border border-zinc-200 dark:border-zinc-800 px-3 py-2 text-[11px] font-mono text-muted-foreground space-y-0.5">
                            <div><span class="text-zinc-500">mission:</span> <span class="text-foreground">{{ bookingConfigPayload.mission }}</span></div>
                            <div><span class="text-zinc-500">ivacCenter:</span> <span class="text-foreground">{{ bookingConfigPayload.ivacCenter }}</span></div>
                        </div>

                        <Result v-if="bookingConfigSetResult" :result="bookingConfigSetResult" />
                    </div>
                </div>
            </div>

            <!-- Sign Up Tab -->
            <div v-show="activeTab === 'signup'" class="space-y-4">
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold">Applicant Details</h2>
                            <p class="text-xs text-muted-foreground mt-0.5">
                                Registers a brand new IVAC account — independent of the account selector above. Both the phone
                                and the email must be OTP-verified before <code class="font-mono">/auth/signup</code> will accept them.
                            </p>
                        </div>
                        <div class="shrink-0">
                            <input ref="signupPdfInput" type="file" accept="application/pdf" class="hidden" @change="onSignupPdfSelected" />
                            <Button size="sm" variant="outline" :disabled="signupPdfBusy" @click="signupPdfInput?.click()">
                                <Loader2 v-if="signupPdfBusy" class="mr-2 size-3 animate-spin" />
                                <Upload v-else class="mr-1.5 size-3" />
                                Fill from PDF
                            </Button>
                        </div>
                    </div>

                    <p v-if="signupPdfStatus" class="text-xs text-emerald-600 dark:text-emerald-400">{{ signupPdfStatus }}</p>
                    <p v-if="signupPdfError" class="text-xs text-red-600 dark:text-red-400">{{ signupPdfError }}</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="text-xs text-muted-foreground">Phone</span>
                            <input v-model="signupForm.phone" type="tel" placeholder="01XXXXXXXXX" autocomplete="off"
                                class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-2 text-sm font-mono" />
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="text-xs text-muted-foreground">Email</span>
                            <input v-model="signupForm.email" type="email" placeholder="applicant@example.com" autocomplete="off"
                                class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-2 text-sm font-mono" />
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="text-xs text-muted-foreground">Given name</span>
                            <input v-model="signupForm.givenName" type="text" autocomplete="off"
                                class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-2 text-sm" />
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="text-xs text-muted-foreground">Surname</span>
                            <input v-model="signupForm.surname" type="text" autocomplete="off"
                                class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-2 text-sm" />
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="text-xs text-muted-foreground">Date of birth</span>
                            <input v-model="signupForm.dob" type="date"
                                class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-2 text-sm" />
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="text-xs text-muted-foreground">Password</span>
                            <input v-model="signupForm.password" type="text" autocomplete="new-password"
                                class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-2 text-sm font-mono" />
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="text-xs text-muted-foreground">NID <span class="text-zinc-400">(optional)</span></span>
                            <input v-model="signupForm.nid" type="text" autocomplete="off"
                                class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-2 text-sm font-mono" />
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="text-xs text-muted-foreground">Passport <span class="text-zinc-400">(optional)</span></span>
                            <input v-model="signupForm.passport" type="text" autocomplete="off"
                                class="rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-2 text-sm font-mono" />
                        </label>
                    </div>
                </div>

                <Step
                    title="1. Send Phone OTP"
                    :desc="`POST ${defaultPath('signupSendOtp')} — body { phone, otpChannel: PHONE } with a RAW turnstile token in x-token.`"
                    url-key="signupSendOtp"
                >
                    <template #actions>
                        <Button size="sm" :disabled="!signupForm.phone.trim() || signupBusy.sendOtpPhone" @click="callSignupSendOtp('phone')">
                            <Loader2 v-if="signupBusy.sendOtpPhone" class="mr-2 size-3 animate-spin" />
                            Send Phone OTP
                        </Button>
                        <span v-if="signupRequestId.phone" class="text-xs text-muted-foreground">
                            requestId: <code class="rounded bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5">{{ signupRequestId.phone }}</code>
                        </span>
                    </template>
                    <Result :result="signupResults.sendOtpPhone" />
                </Step>

                <Step
                    title="2. Verify Phone OTP"
                    :desc="`POST ${defaultPath('signupVerifyOtp')} — body { requestId, phone, code, otpChannel: PHONE }.`"
                    url-key="signupVerifyOtp"
                >
                    <template #actions>
                        <input v-model="signupOtp.phone" type="text" inputmode="numeric" maxlength="8" placeholder="OTP code"
                            class="w-32 rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-1.5 text-sm tracking-widest" />
                        <Button size="sm" variant="outline" :disabled="!signupForm.phone.trim() || signupBusy.fetchOtp" @click="callFetchSignupOtp">
                            <Loader2 v-if="signupBusy.fetchOtp" class="mr-2 size-3 animate-spin" />
                            Fetch OTP
                        </Button>
                        <Button size="sm" :disabled="!signupRequestId.phone || !signupOtp.phone || signupBusy.verifyOtpPhone" @click="callSignupVerifyOtp('phone')">
                            <Loader2 v-if="signupBusy.verifyOtpPhone" class="mr-2 size-3 animate-spin" />
                            Verify Phone
                        </Button>
                        <span v-if="signupVerified.phone" class="inline-flex items-center rounded bg-emerald-500/15 px-2 py-0.5 text-xs text-emerald-700 dark:text-emerald-300">
                            Phone verified
                        </span>
                    </template>
                    <Result :result="signupResults.verifyOtpPhone" />
                </Step>

                <Step
                    title="3. Send Email OTP"
                    :desc="`POST ${defaultPath('signupSendOtp')} — body { email, otpChannel: EMAIL } with a RAW turnstile token in x-token.`"
                >
                    <template #actions>
                        <Button size="sm" :disabled="!signupForm.email.trim() || signupBusy.sendOtpEmail" @click="callSignupSendOtp('email')">
                            <Loader2 v-if="signupBusy.sendOtpEmail" class="mr-2 size-3 animate-spin" />
                            Send Email OTP
                        </Button>
                        <span v-if="signupRequestId.email" class="text-xs text-muted-foreground">
                            requestId: <code class="rounded bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5">{{ signupRequestId.email }}</code>
                        </span>
                    </template>
                    <p class="text-xs text-muted-foreground">Reuses the URL box from step 1 — both channels hit the same endpoint.</p>
                    <Result :result="signupResults.sendOtpEmail" />
                </Step>

                <Step
                    title="4. Verify Email OTP"
                    :desc="`POST ${defaultPath('signupVerifyOtp')} — body { requestId, email, code, otpChannel: EMAIL }.`"
                >
                    <template #actions>
                        <input v-model="signupOtp.email" type="text" inputmode="numeric" maxlength="8" placeholder="OTP code"
                            class="w-32 rounded-md border border-zinc-200 dark:border-zinc-700 bg-transparent px-3 py-1.5 text-sm tracking-widest" />
                        <Button size="sm" :disabled="!signupRequestId.email || !signupOtp.email || signupBusy.verifyOtpEmail" @click="callSignupVerifyOtp('email')">
                            <Loader2 v-if="signupBusy.verifyOtpEmail" class="mr-2 size-3 animate-spin" />
                            Verify Email
                        </Button>
                        <span v-if="signupVerified.email" class="inline-flex items-center rounded bg-emerald-500/15 px-2 py-0.5 text-xs text-emerald-700 dark:text-emerald-300">
                            Email verified
                        </span>
                    </template>
                    <p class="text-xs text-muted-foreground">Reuses the URL box from step 2 — both channels hit the same endpoint.</p>
                    <Result :result="signupResults.verifyOtpEmail" />
                </Step>

                <Step
                    title="5. Create Account"
                    :desc="`POST ${defaultPath('signup')} — body { phone, email, nid, passport, givenName, surName, dob, password }.`"
                    url-key="signup"
                >
                    <template #actions>
                        <Button size="sm" :disabled="!signupCanSubmit || signupBusy.signup" @click="callSignup">
                            <Loader2 v-if="signupBusy.signup" class="mr-2 size-3 animate-spin" />
                            Sign Up
                        </Button>
                        <Button
                            v-if="signupSucceeded"
                            size="sm"
                            variant="outline"
                            :disabled="signupSaveState === 'saving' || signupSaveState === 'saved'"
                            @click="saveSignupAsAccount"
                        >
                            <Loader2 v-if="signupSaveState === 'saving'" class="mr-2 size-3 animate-spin" />
                            <Save v-else class="mr-1.5 size-3" />
                            {{ signupSaveState === 'saved' ? 'Saved to Accounts' : 'Save to Accounts' }}
                        </Button>
                        <span v-if="!signupVerified.phone || !signupVerified.email" class="text-xs text-amber-600 dark:text-amber-400">
                            {{ !signupVerified.phone && !signupVerified.email ? 'Neither channel verified' : (!signupVerified.phone ? 'Phone not verified' : 'Email not verified') }} — IVAC will reject this.
                        </span>
                    </template>
                    <p v-if="signupSaveState === 'saved'" class="text-xs text-emerald-600 dark:text-emerald-400">
                        Saved as a portal account — it is now selectable in the Booking Flow tab.
                    </p>
                    <p v-else-if="signupSaveState === 'error'" class="text-xs text-red-600 dark:text-red-400">
                        Could not save: {{ signupSaveError }}
                    </p>
                    <Result :result="signupResults.signup" />
                </Step>
            </div>
            </div>

            <!-- Right sidebar -->
            <aside v-if="accountId" class="w-full lg:w-72 lg:shrink-0 lg:sticky lg:top-6 space-y-3">
                <!-- Booking Config -->
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
                    <div class="px-3 py-2.5 bg-zinc-50 dark:bg-zinc-950 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                        <span class="text-xs font-semibold">Booking Config</span>
                        <span v-if="sidebarData?.bookingConfig" class="text-[10px] text-zinc-400 tabular-nums">{{ fmtTime(sidebarData.bookingConfig.fetchedAt) }}</span>
                    </div>
                    <div v-if="sidebarData?.bookingConfig" class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <div class="px-3 py-1.5 flex items-start gap-2">
                            <span class="text-[10px] text-zinc-400 w-24 shrink-0 pt-px">Appt ID</span>
                            <span class="text-[10px] font-mono break-all leading-tight">{{ sidebarData.bookingConfig.data.appointmentId ?? '—' }}</span>
                        </div>
                        <div class="px-3 py-1.5 flex items-center gap-2">
                            <span class="text-[10px] text-zinc-400 w-24 shrink-0">Date</span>
                            <span class="text-[11px] font-medium">{{ sidebarData.bookingConfig.data.appointmentDate ?? '—' }}</span>
                        </div>
                        <div class="px-3 py-1.5 flex items-center gap-2">
                            <span class="text-[10px] text-zinc-400 w-24 shrink-0">Slot</span>
                            <span class="text-[11px] font-medium">{{ sidebarData.bookingConfig.data.appointmentSlot ?? '—' }}</span>
                        </div>
                        <div class="px-3 py-1.5 flex items-center gap-2">
                            <span class="text-[10px] text-zinc-400 w-24 shrink-0">Mission</span>
                            <span class="text-[11px] font-medium">{{ sidebarData.bookingConfig.data.mission ?? '—' }}</span>
                        </div>
                        <div class="px-3 py-1.5 flex items-center gap-2">
                            <span class="text-[10px] text-zinc-400 w-24 shrink-0">IVAC Center</span>
                            <span class="text-[11px] font-medium">{{ sidebarData.bookingConfig.data.ivacCenter ?? '—' }}</span>
                        </div>
                        <div class="px-3 py-1.5 flex items-center gap-2">
                            <span class="text-[10px] text-zinc-400 w-24 shrink-0">Status</span>
                            <span class="text-[10px] font-mono">{{ sidebarData.bookingConfig.data.fileUploadStatus ?? '—' }}</span>
                        </div>
                        <div class="px-3 py-1.5 flex items-center gap-2">
                            <span class="text-[10px] text-zinc-400 w-24 shrink-0">Applicants</span>
                            <span class="text-[11px] font-medium">{{ sidebarData.bookingConfig.data.numberOfApplicants ?? '—' }}</span>
                        </div>
                        <div class="px-3 py-1.5 flex items-center gap-2">
                            <span class="text-[10px] text-zinc-400 w-24 shrink-0">Amount</span>
                            <span class="text-[11px] font-medium">{{ sidebarData.bookingConfig.data.totalAmount != null ? `৳${sidebarData.bookingConfig.data.totalAmount}` : '—' }}</span>
                        </div>
                    </div>
                    <div v-else class="px-3 py-5 text-center">
                        <p class="text-[11px] text-zinc-400">Run Get Booking Config to see data</p>
                    </div>
                </div>

                <!-- File Overview -->
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
                    <div class="px-3 py-2.5 bg-zinc-50 dark:bg-zinc-950 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
                        <span class="text-xs font-semibold">File Overview</span>
                        <span v-if="sidebarData?.fileOverview" class="text-[10px] text-zinc-400 tabular-nums">{{ fmtTime(sidebarData.fileOverview.fetchedAt) }}</span>
                    </div>
                    <div v-if="sidebarData?.fileOverview && sidebarData.fileOverview.items.length > 0">
                        <div
                            v-for="(item, i) in sidebarData.fileOverview.items"
                            :key="i"
                            class="px-3 py-2 space-y-1 border-t border-zinc-100 dark:border-zinc-800 first:border-t-0"
                        >
                            <div class="flex items-center gap-1.5 mb-1">
                                <span class="text-[10px] font-mono font-semibold">{{ item.applicationId ?? '—' }}</span>
                                <span v-if="item.primary" class="text-[9px] rounded px-1 py-0.5 bg-emerald-500/15 text-emerald-700 dark:text-emerald-400 font-medium">Primary</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] text-zinc-400 w-20 shrink-0">Name</span>
                                <span class="text-[10px] font-medium break-words">{{ item.fullName ?? '—' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] text-zinc-400 w-20 shrink-0">Passport</span>
                                <span class="text-[10px] font-mono">{{ item.passport ?? '—' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] text-zinc-400 w-20 shrink-0">Visa</span>
                                <span class="text-[10px]">{{ item.visaType ?? '—' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] text-zinc-400 w-20 shrink-0">Commission</span>
                                <span class="text-[10px]">{{ item.commissionName ?? '—' }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="px-3 py-5 text-center">
                        <p class="text-[11px] text-zinc-400">Run File Overview to see data</p>
                    </div>
                </div>

                <!-- PDF Edit Profile -->
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
                    <div class="px-3 py-2.5 bg-zinc-50 dark:bg-zinc-950 border-b border-zinc-200 dark:border-zinc-800">
                        <span class="text-xs font-semibold">PDF Edit Profile</span>
                    </div>
                    <div v-if="selectedPdfProfile" class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <div class="px-3 py-1.5 flex items-center gap-2">
                            <span class="text-[10px] text-zinc-400 w-24 shrink-0">Surname</span>
                            <span class="text-[10px] font-mono">{{ selectedPdfProfile.surname || '—' }}</span>
                        </div>
                        <div class="px-3 py-1.5 flex items-center gap-2">
                            <span class="text-[10px] text-zinc-400 w-24 shrink-0">Given Name</span>
                            <span class="text-[10px] font-mono">{{ selectedPdfProfile.given_name || '—' }}</span>
                        </div>
                        <div class="px-3 py-1.5 flex items-center gap-2">
                            <span class="text-[10px] text-zinc-400 w-24 shrink-0">Passport No</span>
                            <span class="text-[10px] font-mono">{{ selectedPdfProfile.passport_no || '—' }}</span>
                        </div>
                        <div class="px-3 py-1.5 flex items-center gap-2">
                            <span class="text-[10px] text-zinc-400 w-24 shrink-0">Phone</span>
                            <span class="text-[10px] font-mono">{{ selectedPdfProfile.pdf_phone || '—' }}</span>
                        </div>
                        <div class="px-3 py-1.5 flex items-center gap-2">
                            <span class="text-[10px] text-zinc-400 w-24 shrink-0">Email</span>
                            <span class="text-[10px] break-all">{{ selectedPdfProfile.email || '—' }}</span>
                        </div>
                    </div>
                    <div v-else class="px-3 py-5 text-center">
                        <p class="text-[11px] text-zinc-400">No saved profile for this account</p>
                    </div>
                </div>
            </aside>
        </div>
    </AppLayout>
</template>
