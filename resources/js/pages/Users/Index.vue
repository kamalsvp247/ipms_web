<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { Search, Plus, MoreVertical, Edit2, Trash2, ShieldCheck, CircleUser, Angry, UserCog, LogIn, Eye, EyeOff, CheckCircle, XCircle, Loader2, Copy, Check } from 'lucide-vue-next';
import { ref, onMounted, computed, watch } from 'vue';
import DataTablePagination from '@/components/DataTablePagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import Switch from '@/components/ui/Switch.vue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';

type Role = 'super_admin' | 'manager' | 'sub_manager' | 'agent';

interface UserRow {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role: Role;
    parent_id: number | null;
    manager: { id: number; name: string } | null;
    referral_code: string | null;
    plain_password?: string | null;
    accounts_count: number;
    agents_count: number;
    running_accounts_count: number;
    booked_today_count: number;
    created_at: string;
    is_approved: boolean;
}

const ROLE_LABELS: Record<Role, string> = {
    super_admin: 'Admin',
    manager: 'Manager',
    sub_manager: 'Sub Manager',
    agent: 'Agent',
};

/** Mirrors User::ROLE_RANKS — lowest is the most privileged. */
const ROLE_RANK: Record<Role, number> = {
    super_admin: 0,
    manager: 1,
    sub_manager: 2,
    agent: 3,
};

/** Mirrors User::parentRolesFor() — who may own a user of each role. */
const PARENT_ROLES: Record<Role, Role[]> = {
    super_admin: [],
    manager: [],
    sub_manager: ['manager'],
    agent: ['manager', 'sub_manager'],
};

const users = ref<UserRow[]>([]);
const paginationMeta = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    from: 0,
    to: 0,
});
const totalAccounts = ref(0);
const runningAccounts = ref(0);
const bookedToday = ref(0);
const loading = ref(true);
const isSheetOpen = ref(false);
const editingUser = ref<UserRow | null>(null);
const searchQuery = ref('');
const formErrors = ref<string[]>([]);

const PER_PAGE = 100;

const form = ref({
    name: '',
    email: '',
    phone: '',
    password: '',
    role: 'agent' as Role,
    parent_id: null as number | null,
});

/**
 * A random password that satisfies Password::defaults() in production (12+ chars, mixed case,
 * a digit, and a symbol), generated with one guaranteed character from each required set.
 */
const generatePassword = () => {
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lower = 'abcdefghijkmnpqrstuvwxyz';
    const digits = '23456789';
    const symbols = '!@#$%^&*';
    const all = upper + lower + digits + symbols;

    const pick = (set: string) => set[Math.floor(Math.random() * set.length)];
    const required = [pick(upper), pick(lower), pick(digits), pick(symbols)];
    const rest = Array.from({ length: 8 }, () => pick(all));

    form.value.password = [...required, ...rest].sort(() => Math.random() - 0.5).join('');
    showPassword.value = true;
};

const showPassword = ref(false);
const showPasswords = ref(false);
const togglePasswords = () => { showPasswords.value = !showPasswords.value; };

/** Roles the signed-in user may assign, and the owners they may parent a new user to. */
const assignableRoles = ref<Role[]>([]);
const managers = ref<{ id: number; name: string; email: string; role: Role }[]>([]);

/** Only the owners allowed to hold the role being created, so the picker cannot build an
 * invalid tree (a sub manager under another sub manager, say). */
const parentOptions = computed(() => {
    const allowed = PARENT_ROLES[form.value.role] ?? [];

    return managers.value.filter((m) => allowed.includes(m.role));
});

// Switching role can strand a parent the new role may not have (an agent under a sub manager
// promoted to sub manager, say), so drop any selection the picker no longer offers.
watch(
    () => form.value.role,
    () => {
        if (form.value.parent_id !== null && !parentOptions.value.some((m) => m.id === form.value.parent_id)) {
            form.value.parent_id = null;
        }
    },
);

const fetchOptions = async () => {
    try {
        const { data } = await axios.get('/api/users/options');
        assignableRoles.value = data.roles ?? [];
        managers.value = data.managers ?? [];
    } catch {
        assignableRoles.value = [];
        managers.value = [];
    }
};

const fetchUsers = async (page = 1) => {
    loading.value = true;
    try {
        const response = await axios.get('/api/users', {
            params: { page, search: searchQuery.value, per_page: PER_PAGE },
        });
        users.value = response.data.data;
        paginationMeta.value = {
            current_page: response.data.current_page,
            last_page: response.data.last_page,
            total: response.data.total,
            from: response.data.from,
            to: response.data.to,
        };
        totalAccounts.value = response.data.total_accounts;
        runningAccounts.value = response.data.running_accounts;
        bookedToday.value = response.data.booked_today;
    } catch (error) {
        console.error('Failed to fetch users', error);
    } finally {
        loading.value = false;
    }
};

let searchTimeout: ReturnType<typeof setTimeout>;
const onSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => fetchUsers(1), 400);
};

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString() +
    ' ' +
    new Date(date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

const openCreateSheet = () => {
    editingUser.value = null;
    formErrors.value = [];
    showPassword.value = false;
    form.value = {
        name: '',
        email: '',
        phone: '',
        password: '',
        role: assignableRoles.value.includes('agent') ? 'agent' : (assignableRoles.value[0] ?? 'agent'),
        parent_id: null,
    };
    isSheetOpen.value = true;
};

const openEditSheet = (user: UserRow) => {
    editingUser.value = user;
    formErrors.value = [];
    showPassword.value = false;
    form.value = {
        name: user.name,
        email: user.email,
        phone: user.phone ?? '',
        password: '',
        role: user.role,
        parent_id: user.parent_id,
    };
    isSheetOpen.value = true;
};

const saveUser = async () => {
    formErrors.value = [];
    try {
        const payload: Record<string, unknown> = {
            name: form.value.name,
            email: form.value.email,
            phone: form.value.phone || null,
        };

        // Only an admin may pick an owner, and only an admin may re-role an existing user;
        // the server rejects either from anyone else, so omit them entirely rather than
        // sending values that would 422. An owner still chooses the role when creating,
        // since a manager may mint either a sub manager or an agent.
        if (canAssignParent.value) {
            payload.role = form.value.role;
            payload.parent_id = needsParent.value ? form.value.parent_id : null;
        } else if (!editingUser.value) {
            payload.role = form.value.role;
        }

        if (form.value.password) {
            payload.password = form.value.password;
        }

        if (editingUser.value) {
            await axios.put(`/api/users/${editingUser.value.id}`, payload);
        } else {
            payload.password = form.value.password;
            await axios.post('/api/users', payload);
        }
        isSheetOpen.value = false;
        fetchUsers(paginationMeta.value.current_page);
    } catch (error: any) {
        const errors = error?.response?.data?.errors;
        if (errors) {
            formErrors.value = Object.values(errors).flat() as string[];
        } else {
            formErrors.value = [error?.response?.data?.message ?? 'Failed to save user.'];
        }
    }
};

const deleteUser = async (user: UserRow) => {
    if (!confirm(`Delete user "${user.name}"? This cannot be undone.`)) return;
    try {
        await axios.delete(`/api/users/${user.id}`);
        fetchUsers(paginationMeta.value.current_page);
    } catch (error: any) {
        alert(error?.response?.data?.message ?? 'Failed to delete user.');
    }
};

const page = usePage();
const currentUserId = computed(() => (page.props.auth as any)?.user?.id);
const currentRole = computed<Role>(() => (page.props.auth as any)?.user?.role ?? 'agent');
const isSuperAdmin = computed(() => currentRole.value === 'super_admin');

/**
 * Everyone on this page may administer users. The role select appears whenever the actor has
 * a real choice — an admin picks any role, a manager picks sub manager or agent — while the
 * owner picker stays admin-only, since everybody else parents to themselves.
 */
const canManageUsers = computed(() => (page.props.auth as any)?.permissions?.['users.manage'] === true);
const canAssignRoles = computed(() => assignableRoles.value.length > 1);
const canAssignParent = computed(() => isSuperAdmin.value);

/** Whether the role being created hangs off an owner at all. */
const needsParent = computed(() => (PARENT_ROLES[form.value.role] ?? []).length > 0);

/** Re-roling an existing user is admin-only, so an owner sees the select on create only. */
const canPickRole = computed(() => canAssignRoles.value && (isSuperAdmin.value || !editingUser.value));

/** Base columns plus the two conditional ones (admin-only Manager, and Password + Actions). */
const columnCount = computed(() => 10 + (isSuperAdmin.value ? 1 : 0) + (canManageUsers.value ? 2 : 0));

/**
 * The Total row's Accounts/Running/Booked cells sit between the Password column (before) and
 * the Created/Actions columns (after), so the label/trailing colspans must count them on the
 * correct side rather than splitting columnCount evenly.
 */
const totalLabelColspan = computed(() => 6 + (isSuperAdmin.value ? 1 : 0) + (canManageUsers.value ? 1 : 0));
const totalTrailingColspan = computed(() => 1 + (canManageUsers.value ? 1 : 0));

interface UserGroup {
    key: string;
    label: string;
    showLabel: boolean;
    referralCode: string | null;
    rows: { row: UserRow; index: number; depth: number }[];
}

/**
 * Sections by owner (sorted by name), each owner's own row followed by their subtree in tree
 * order: direct agents first, then each sub manager with its own agents nested under it.
 * Admins and users whose owner is outside the visible set get their own labeled sections.
 */
const displayGroups = computed<UserGroup[]>(() => {
    const byName = (a: UserRow, b: UserRow) => a.name.localeCompare(b.name);
    const list = users.value;

    const admins = list.filter((u) => u.role === 'super_admin').sort(byName);
    const byId = new Map(list.map((u) => [u.id, u]));

    // Agents sort ahead of sub managers so a manager's own agents read as one block before
    // the nested sub-manager subtrees begin.
    const childOrder = (a: UserRow, b: UserRow) =>
        a.role === b.role ? byName(a, b) : ROLE_RANK[b.role] - ROLE_RANK[a.role];

    const childrenOf = new Map<number, UserRow[]>();
    const roots: UserRow[] = [];
    list.filter((u) => u.role !== 'super_admin').forEach((u) => {
        if (u.parent_id !== null && byId.has(u.parent_id)) {
            if (!childrenOf.has(u.parent_id)) childrenOf.set(u.parent_id, []);
            childrenOf.get(u.parent_id)!.push(u);
        } else {
            roots.push(u);
        }
    });
    childrenOf.forEach((arr) => arr.sort(childOrder));

    const flatten = (user: UserRow, depth: number): { row: UserRow; depth: number }[] => [
        { row: user, depth },
        ...(childrenOf.get(user.id) ?? []).flatMap((child) => flatten(child, depth + 1)),
    ];

    const owners = roots.filter((u) => u.role === 'manager' || u.role === 'sub_manager').sort(byName);
    const looseAgents = roots.filter((u) => u.role === 'agent').sort(byName);

    const groups: { key: string; label: string; showLabel: boolean; referralCode: string | null; rows: { row: UserRow; depth: number }[] }[] = [];
    if (admins.length) {
        groups.push({
            key: 'admins',
            label: 'Administrators',
            showLabel: true,
            referralCode: null,
            rows: admins.map((row) => ({ row, depth: 0 })),
        });
    }
    owners.forEach((owner) => {
        groups.push({
            key: `owner-${owner.id}`,
            label: owner.name,
            showLabel: true,
            referralCode: owner.referral_code,
            rows: flatten(owner, 0),
        });
    });
    if (looseAgents.length) {
        groups.push({
            key: 'unassigned',
            label: 'Unassigned',
            showLabel: true,
            referralCode: null,
            rows: looseAgents.map((row) => ({ row, depth: 0 })),
        });
    }

    let idx = 0;
    return groups.map((g) => ({
        ...g,
        rows: g.rows.map(({ row, depth }) => ({ row, depth, index: idx++ })),
    }));
});

/** Ids below the signed-in user in the loaded list, walked the same way the server does. */
const subtreeIds = computed(() => {
    const childrenOf = new Map<number, number[]>();
    users.value.forEach((u) => {
        if (u.parent_id === null) return;
        if (!childrenOf.has(u.parent_id)) childrenOf.set(u.parent_id, []);
        childrenOf.get(u.parent_id)!.push(u.id);
    });

    const ids = new Set<number>();
    const frontier = [currentUserId.value];
    while (frontier.length) {
        (childrenOf.get(frontier.pop()!) ?? []).forEach((id) => {
            if (ids.has(id)) return;
            ids.add(id);
            frontier.push(id);
        });
    }

    return ids;
});

/**
 * A row is actionable when the server would allow it, mirroring User::canManageUser: never
 * yourself, and for an owner only someone ranked below them inside their own subtree. Rows
 * outside the subtree never reach the client at all, so this mainly suppresses self-actions
 * and, for a manager, the peer rows a sub manager's own view would never contain.
 */
const canManageRow = (user: UserRow) => {
    if (user.id === currentUserId.value) return false;
    if (isSuperAdmin.value) return true;

    return ROLE_RANK[user.role] > ROLE_RANK[currentRole.value] && subtreeIds.value.has(user.id);
};

const loginAs = (user: UserRow) => {
    router.post(`/users/${user.id}/impersonate`);
};

const copiedReferralId = ref<number | null>(null);

const copyReferralCode = async (user: UserRow) => {
    if (!user.referral_code) return;
    try {
        await navigator.clipboard.writeText(user.referral_code);
        copiedReferralId.value = user.id;
        setTimeout(() => {
            if (copiedReferralId.value === user.id) copiedReferralId.value = null;
        }, 1500);
    } catch {
        toast.error('Failed to copy to clipboard');
    }
};

const copiedCredentialsId = ref<number | null>(null);

/**
 * The hand-off block an owner sends a new user: the portal to sign in at, then their name,
 * email and password one per line. The link comes from window.location.origin so a copy made
 * on a staging host never hands out the production one, and the password line is dropped
 * rather than filled with a placeholder when no stored password came back.
 */
const credentialsText = (user: UserRow) =>
    [
        window.location.origin,
        '',
        `Name: ${user.name}`,
        `Email: ${user.email}`,
        ...(user.plain_password ? [`Password: ${user.plain_password}`] : []),
    ].join('\n');

const copyCredentials = async (user: UserRow) => {
    try {
        await navigator.clipboard.writeText(credentialsText(user));
        copiedCredentialsId.value = user.id;
        setTimeout(() => {
            if (copiedCredentialsId.value === user.id) copiedCredentialsId.value = null;
        }, 1500);
    } catch {
        toast.error('Failed to copy to clipboard');
    }
};

const togglingApprovalId = ref<number | null>(null);

const toggleApproval = async (user: UserRow) => {
    if (togglingApprovalId.value !== null) return;
    togglingApprovalId.value = user.id;
    const wasApproved = user.is_approved;
    user.is_approved = !wasApproved;
    try {
        const endpoint = wasApproved ? `/api/users/${user.id}/unapprove` : `/api/users/${user.id}/approve`;
        await axios.post(endpoint);
    } catch (error: any) {
        user.is_approved = wasApproved;
        alert(error?.response?.data?.message ?? 'Failed to update approval status.');
    } finally {
        togglingApprovalId.value = null;
    }
};

const approveUser = async (user: UserRow) => {
    try {
        await axios.post(`/api/users/${user.id}/approve`);
        fetchUsers(paginationMeta.value.current_page);
    } catch (error: any) {
        alert(error?.response?.data?.message ?? 'Failed to approve user.');
    }
};

const rejectUser = async (user: UserRow) => {
    if (!confirm(`Reject and delete "${user.name}"? This cannot be undone.`)) return;
    try {
        await axios.delete(`/api/users/${user.id}/reject`);
        fetchUsers(paginationMeta.value.current_page);
    } catch (error: any) {
        alert(error?.response?.data?.message ?? 'Failed to reject user.');
    }
};

onMounted(() => {
    fetchUsers();
    fetchOptions();
});

const breadcrumbs = computed(() => [
    ...(isSuperAdmin.value ? [{ title: 'Dashboard', href: '/dashboard' }] : []),
    { title: 'Manage Users', href: '/users' },
]);
</script>

<template>
    <Head title="Manage Users" />
    <AppLayout :breadcrumbs="breadcrumbs" full-width>
        <div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
            <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-400 to-blue-600 shadow-sm shadow-blue-500/30">
                        <CircleUser class="h-5 w-5 text-white" />
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Users</h2>
                        <p class="text-muted-foreground mt-0.5 text-sm">Create and manage user accounts and their roles.</p>
                    </div>
                </div>
                <Button v-if="canManageUsers" @click="openCreateSheet" class="w-full md:w-auto">
                    <Plus class="mr-2 h-4 w-4" />
                    Add User
                </Button>
            </div>

            <div class="flex items-center gap-3">
                <div class="relative w-full flex-1 max-w-2xl">
                    <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input v-model="searchQuery" placeholder="Search by name, email or phone..." class="pl-9" @input="onSearch" />
                </div>
                <Button v-if="canManageUsers" variant="outline" size="sm" @click="togglePasswords">
                    <EyeOff v-if="showPasswords" class="mr-2 h-4 w-4" />
                    <Eye v-else class="mr-2 h-4 w-4" />
                    {{ showPasswords ? 'Hide' : 'Show' }} Passwords
                </Button>
            </div>

            <div class="rounded-lg border border-zinc-200/60 dark:border-zinc-700/60 bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm overflow-hidden">
                <Table class="border-b whitespace-nowrap">
                    <TableHeader class="bg-zinc-50/60 dark:bg-zinc-900/40 backdrop-blur-sm border-b border-zinc-200/60 dark:border-zinc-700/60">
                        <TableRow>
                            <TableHead class="pl-3 pr-2 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest border-r w-[3.125rem]">S/N</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Name</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Email</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Phone</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Role</TableHead>
                            <TableHead v-if="isSuperAdmin" class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Manager</TableHead>
                            <TableHead class="px-2 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Status</TableHead>
                            <TableHead v-if="canManageUsers" class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Password</TableHead>
                            <TableHead class="px-2 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest border-l">Accounts</TableHead>
                            <TableHead class="px-2 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Running</TableHead>
                            <TableHead class="px-2 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest border-r">Booked Today</TableHead>
                            <TableHead class="px-2 py-2 text-left font-semibold text-zinc-400 text-[10px] uppercase tracking-widest">Created</TableHead>
                            <TableHead v-if="canManageUsers" class="pl-2 pr-3 py-2 text-center font-semibold text-zinc-400 text-[10px] uppercase tracking-widest border-l w-[3.75rem]">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-if="loading" v-for="i in 3" :key="i">
                            <TableCell v-for="j in columnCount" :key="j" :class="{ 'border-r': j === 1, 'border-l': canManageUsers && j === columnCount }">
                                <div class="h-5 w-full animate-pulse rounded bg-muted"></div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-else-if="users.length === 0" class="h-24 text-center">
                            <TableCell :colspan="columnCount" class="text-muted-foreground">No users found.</TableCell>
                        </TableRow>
                        <template v-else v-for="group in displayGroups" :key="group.key">
                        <TableRow v-if="group.showLabel" class="bg-zinc-100/70 dark:bg-zinc-800/40">
                            <TableCell :colspan="columnCount" class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-widest text-zinc-600 dark:text-zinc-300">
                                <span class="inline-flex items-center gap-1.5">
                                    {{ group.label }}
                                    <span class="text-zinc-400 font-normal normal-case">{{ group.rows.length }} user{{ group.rows.length === 1 ? '' : 's' }}</span>
                                    <button
                                        v-if="group.referralCode && (isSuperAdmin || group.rows[0]?.row.id === currentUserId)"
                                        @click="copyReferralCode(group.rows[0].row)"
                                        :title="'Copy referral code: ' + group.referralCode"
                                        class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-mono font-normal normal-case bg-zinc-200/70 text-zinc-600 hover:bg-zinc-300/70 dark:bg-zinc-700/60 dark:text-zinc-300 dark:hover:bg-zinc-600/60 cursor-pointer whitespace-nowrap"
                                    >
                                        <Check v-if="copiedReferralId === group.rows[0]?.row.id" class="h-3 w-3 text-emerald-600" />
                                        <Copy v-else class="h-3 w-3" />
                                        {{ group.referralCode }}
                                    </button>
                                </span>
                            </TableCell>
                        </TableRow>
                        <TableRow v-for="{ row: user, index, depth } in group.rows" :key="user.id"
                            class="transition-colors hover:bg-sky-50 dark:hover:bg-sky-900/20"
                            :class="index % 2 === 0 ? 'bg-white dark:bg-zinc-950/20' : 'bg-zinc-100/70 dark:bg-zinc-800/30'">
                            <TableCell class="pl-3 pr-2 py-1.5 text-center text-[10px] text-zinc-400 dark:text-zinc-600 font-mono tabular-nums border-r">
                                {{ (paginationMeta.current_page - 1) * PER_PAGE + index + 1 }}
                            </TableCell>
                            <!-- Indented by depth so a sub manager's agents read as sitting under it. -->
                            <TableCell class="px-2 py-1.5 font-semibold text-[11px]" :style="{ paddingLeft: `${8 + depth * 14}px` }">{{ user.name }}</TableCell>
                            <TableCell class="px-2 py-1.5 text-[11px] text-zinc-600 dark:text-zinc-400">{{ user.email }}</TableCell>
                            <TableCell class="px-2 py-1.5 text-[11px] text-zinc-600 dark:text-zinc-400">{{ user.phone ?? '—' }}</TableCell>
                            <TableCell class="px-2 py-1.5">
                                <span v-if="user.role === 'super_admin'" class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold bg-emerald-100 text-black">
                                    <ShieldCheck class="h-3 w-3" />
                                    Admin
                                </span>
                                <span v-else-if="user.role === 'manager'" class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold bg-violet-100 text-black">
                                    <Angry class="h-3 w-3" />
                                    Manager
                                    <span v-if="user.agents_count" class="ml-0.5 rounded bg-violet-200/70 px-1 tabular-nums dark:bg-violet-800/50">{{ user.agents_count }}</span>
                                </span>
                                <span v-else-if="user.role === 'sub_manager'" class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold bg-amber-100 text-black">
                                    <UserCog class="h-3 w-3" />
                                    Sub Manager
                                    <span v-if="user.agents_count" class="ml-0.5 rounded bg-amber-200/70 px-1 tabular-nums dark:bg-amber-800/50">{{ user.agents_count }}</span>
                                </span>
                                <span v-else class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold bg-blue-100 text-black">
                                    <CircleUser class="h-3 w-3" />
                                    Agent
                                </span>
                            </TableCell>
                            <TableCell v-if="isSuperAdmin" class="px-2 py-1.5 text-[11px] text-zinc-600 dark:text-zinc-400">
                                <span v-if="user.manager">{{ user.manager.name }}</span>
                                <span v-else class="text-zinc-400">—</span>
                            </TableCell>
                            <TableCell class="px-2 py-1.5">
                                <div class="flex items-center justify-center gap-2">
                                    <Loader2 v-if="togglingApprovalId === user.id" class="h-3.5 w-3.5 animate-spin text-zinc-400" />
                                    <Switch v-else :model-value="user.is_approved"
                                        :disabled="!canManageRow(user)"
                                        @update:model-value="() => toggleApproval(user)" />
                                    <span class="text-[10px] font-medium"
                                        :class="user.is_approved ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400'">
                                        {{ user.is_approved ? 'Approved' : 'Pending' }}
                                    </span>
                                </div>
                            </TableCell>
                            <TableCell v-if="canManageUsers" class="px-2 py-1.5 text-[11px] font-mono">
                                <template v-if="user.plain_password">
                                    <span v-if="showPasswords">{{ user.plain_password }}</span>
                                    <span v-else class="tracking-widest text-zinc-400">••••••••</span>
                                </template>
                                <span v-else class="text-zinc-400">—</span>
                            </TableCell>
                            <TableCell class="px-2 py-1.5 text-center text-[12px] font-mono font-bold tabular-nums border-l">{{ user.accounts_count }}</TableCell>
                            <TableCell class="px-2 py-1.5 text-center text-[12px] font-mono font-bold tabular-nums" :class="user.running_accounts_count > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400'">{{ user.running_accounts_count }}</TableCell>
                            <TableCell class="px-2 py-1.5 text-center text-[12px] font-mono font-bold tabular-nums border-r" :class="user.booked_today_count > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400'">{{ user.booked_today_count }}</TableCell>
                            <TableCell class="px-2 py-1.5 text-[10px] text-zinc-400">{{ formatDate(user.created_at) }}</TableCell>
                            <TableCell v-if="canManageUsers" class="pl-2 pr-3 py-1.5 border-l">
                                <div class="flex items-center justify-center gap-0.5">
                                <!-- Portal link + login, ready to paste to the user. -->
                                <button
                                    type="button"
                                    @click="copyCredentials(user)"
                                    :title="`Copy portal link and login for ${user.name}`"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded text-zinc-400 transition-colors hover:bg-zinc-200/70 hover:text-zinc-700 dark:hover:bg-zinc-700/50 dark:hover:text-zinc-200"
                                    :class="copiedCredentialsId === user.id ? 'text-emerald-600 dark:text-emerald-400' : ''"
                                >
                                    <Check v-if="copiedCredentialsId === user.id" class="h-3.5 w-3.5" />
                                    <Copy v-else class="h-3.5 w-3.5" />
                                </button>
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button variant="ghost" size="icon" class="h-8 w-8">
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" class="w-44">
                                        <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                        <DropdownMenuSeparator />
                                        <template v-if="!user.is_approved && canManageRow(user)">
                                            <DropdownMenuItem @click="approveUser(user)" class="text-emerald-600 dark:text-emerald-400">
                                                <CheckCircle class="mr-2 h-4 w-4" /> Approve
                                            </DropdownMenuItem>
                                            <DropdownMenuItem @click="rejectUser(user)" class="text-destructive">
                                                <XCircle class="mr-2 h-4 w-4" /> Reject
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                        </template>
                                        <DropdownMenuItem
                                            v-if="canManageRow(user) && user.is_approved"
                                            @click="loginAs(user)"
                                        >
                                            <LogIn class="mr-2 h-4 w-4" /> Login as
                                        </DropdownMenuItem>
                                        <DropdownMenuItem v-if="canManageRow(user)" @click="openEditSheet(user)">
                                            <Edit2 class="mr-2 h-4 w-4" /> Edit
                                        </DropdownMenuItem>
                                        <DropdownMenuItem v-if="canManageRow(user)" @click="deleteUser(user)" class="text-destructive">
                                            <Trash2 class="mr-2 h-4 w-4" /> Delete
                                        </DropdownMenuItem>
                                        <DropdownMenuItem v-if="!canManageRow(user)" disabled class="text-[11px] text-zinc-400">
                                            No actions available
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                                </div>
                            </TableCell>
                        </TableRow>
                        </template>
                        <TableRow v-if="!loading && users.length > 0" class="border-t border-zinc-200 dark:border-zinc-700 bg-zinc-100/60 dark:bg-zinc-900/60">
                            <TableCell :colspan="totalLabelColspan" class="px-2 py-1.5 text-right text-[10px] font-semibold uppercase tracking-widest text-zinc-500">
                                Total
                            </TableCell>
                            <TableCell class="px-2 py-1.5 text-center text-[13px] font-mono font-bold tabular-nums border-l">{{ totalAccounts }}</TableCell>
                            <TableCell class="px-2 py-1.5 text-center text-[13px] font-mono font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ runningAccounts }}</TableCell>
                            <TableCell class="px-2 py-1.5 text-center text-[13px] font-mono font-bold tabular-nums text-blue-600 dark:text-blue-400 border-r">{{ bookedToday }}</TableCell>
                            <TableCell :colspan="totalTrailingColspan"></TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <DataTablePagination :meta="paginationMeta" @page-change="fetchUsers" />
            </div>

            <!-- Create / Edit Sheet -->
            <Sheet v-model:open="isSheetOpen">
                <SheetContent side="right" class="w-full sm:max-w-md flex flex-col p-0">
                    <SheetHeader class="p-3 border-b border-zinc-200 dark:border-zinc-800">
                        <SheetTitle class="text-[14px] font-semibold">{{ editingUser ? 'Edit User' : 'New User' }}</SheetTitle>
                        <SheetDescription class="text-[11px]">
                            {{ editingUser ? 'Update the user\'s details or role.' : 'Create a new user account.' }}
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex flex-col gap-1.5 px-3 py-3 flex-1 overflow-y-auto">
                        <div class="grid gap-1.5">
                            <Label for="name" class="text-[11px] font-semibold">Name</Label>
                            <Input id="name" v-model="form.name" placeholder="Full name" class="h-8 text-[11px]" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="email" class="text-[11px] font-semibold">Email</Label>
                            <Input id="email" type="email" v-model="form.email" placeholder="user@example.com" class="h-8 text-[11px]" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="phone" class="text-[11px] font-semibold">
                                Phone
                                <span class="text-zinc-400 font-normal">(optional)</span>
                            </Label>
                            <Input id="phone" type="tel" v-model="form.phone" placeholder="01XXXXXXXXX" class="h-8 text-[11px]" />
                        </div>
                        <div class="grid gap-1.5">
                            <div class="flex items-center justify-between">
                                <Label for="password" class="text-[11px] font-semibold">
                                    Password
                                    <span v-if="editingUser" class="text-zinc-400 font-normal">(leave blank to keep current)</span>
                                </Label>
                                <button
                                    type="button"
                                    @click="generatePassword"
                                    class="text-[10px] font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                                >
                                    Generate
                                </button>
                            </div>
                            <div class="relative">
                                <Input
                                    id="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    v-model="form.password"
                                    placeholder="••••••••"
                                    class="h-8 text-[11px] pr-8"
                                />
                                <button
                                    type="button"
                                    @click="showPassword = !showPassword"
                                    class="absolute inset-y-0 right-0 flex items-center px-2 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300"
                                    tabindex="-1"
                                >
                                    <EyeOff v-if="showPassword" class="h-3.5 w-3.5" />
                                    <Eye v-else class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                        <div v-if="canPickRole" class="grid gap-1.5">
                            <Label for="role" class="text-[11px] font-semibold">Role</Label>
                            <select
                                id="role"
                                v-model="form.role"
                                class="h-8 w-full rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-2 text-[11px]"
                            >
                                <option v-for="r in assignableRoles" :key="r" :value="r">{{ ROLE_LABELS[r] }}</option>
                            </select>
                        </div>
                        <!-- A sub manager mints only agents under themselves, so there is nothing to pick. -->
                        <div v-else-if="!editingUser" class="rounded border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900/40 px-2 py-1.5">
                            <p class="text-[10px] text-zinc-500 dark:text-zinc-400">
                                This user is an <span class="font-semibold text-blue-600 dark:text-blue-400">Agent</span> under your management.
                            </p>
                        </div>

                        <div v-if="canAssignParent && needsParent" class="grid gap-1.5">
                            <Label for="parent" class="text-[11px] font-semibold">
                                Manager
                                <span class="text-zinc-400 font-normal">
                                    {{ form.role === 'sub_manager' ? '(required — who this sub manager reports to)' : '(who can see this agent)' }}
                                </span>
                            </Label>
                            <select
                                id="parent"
                                v-model="form.parent_id"
                                class="h-8 w-full rounded border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-950 px-2 text-[11px]"
                            >
                                <option v-if="form.role !== 'sub_manager'" :value="null">— None (admin only) —</option>
                                <option v-else :value="null">— Select a manager —</option>
                                <option v-for="m in parentOptions" :key="m.id" :value="m.id">
                                    {{ m.name }} ({{ m.email }}){{ m.role === 'sub_manager' ? ' — Sub Manager' : '' }}
                                </option>
                            </select>
                        </div>

                        <div v-if="formErrors.length > 0" class="rounded border border-red-200 dark:border-red-900/40 bg-red-50 dark:bg-red-950/20 px-2 py-1.5">
                            <p v-for="(err, i) in formErrors" :key="i" class="text-[10px] text-red-700 dark:text-red-400">{{ err }}</p>
                        </div>
                    </div>

                    <div class="p-3 border-t border-zinc-200 dark:border-zinc-800 flex gap-2">
                        <Button variant="outline" @click="isSheetOpen = false" class="h-8 text-[11px]">Cancel</Button>
                        <Button @click="saveUser" class="h-8 text-[11px] bg-emerald-600 hover:bg-emerald-700 text-white">{{ editingUser ? 'Save Changes' : 'Create User' }}</Button>
                    </div>
                </SheetContent>
            </Sheet>
        </div>
    </AppLayout>
</template>
