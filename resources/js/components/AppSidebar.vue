<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart2, Bot, CircleUser, Cpu, Fingerprint, Globe, KeyRound, LayoutGrid, Link as LinkIcon, Mail, Megaphone, Network, Puzzle, ScanSearch, Shield, Smartphone, TerminalSquare, Users, Wallet, Waypoints } from 'lucide-vue-next';
import { computed } from 'vue';
import NavFooter from '@/components/NavFooter.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import AppLogo from './AppLogo.vue';

/** A labelled sidebar section. External sections render plain anchors instead of Inertia links. */
type NavSection = {
    label: string;
    items: NavItem[];
    external?: boolean;
};

const page = usePage();
const permissions = computed(() => page.props.auth.permissions);
const isSuperAdmin = computed(() => permissions.value?.['bot.manage'] === true);
// Managers administer their own agents and read their logs without being admins.
const canManageUsers = computed(() => permissions.value?.['users.manage'] === true);
const canReadLogs = computed(() => permissions.value?.['logs.read'] === true);
const canReadAccounts = computed(() => permissions.value?.['accounts.read'] === true);
const canReadCaptcha = computed(() => permissions.value?.['captcha.read'] === true);
const canAssignAccounts = computed(() => permissions.value?.['accounts.assign'] === true);
const canAccessApiTester = computed(() => permissions.value?.['api-tester.access'] === true);
const canEditNotice = computed(() => permissions.value?.['notice.write'] === true);
// Personal Rocket/Nagad wallet book — agent-only, and private to each agent (enforced server-side too).
const isAgent = computed(() => (page.props.auth?.user as { role?: string } | undefined)?.role === 'agent');
const { isCurrentUrl } = useCurrentUrl();

const groupClass = 'px-2 py-1.5';
const separatorClass = 'my-2';

// Only the icon is color-coded per item; nav text always stays the default color and bold.
const iconColorClass = (title: string): string | undefined => {
    switch (title) {
        case 'Bot Control': return 'text-amber-600 dark:text-amber-400';
        case 'Algorithm Monitor': return 'text-violet-600 dark:text-violet-400';
        case 'Payment Links': return 'text-emerald-600 dark:text-emerald-400';
        default: return undefined;
    }
};

const overviewItems = computed<NavItem[]>(() =>
    isSuperAdmin.value ? [{ title: 'Dashboard', href: dashboard(), icon: LayoutGrid }] : [],
);

const managementItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];
    if (canManageUsers.value) {
        items.push({ title: 'Users', href: '/users', icon: CircleUser });
    }
    if (isAgent.value) {
        items.push({ title: 'Wallet', href: '/wallet', icon: Wallet });
    }
    if (canReadAccounts.value) {
        items.push({ title: 'Accounts', href: '/accounts', icon: Users });
    }
    if (canEditNotice.value) {
        items.push({ title: 'Notice', href: '/notice', icon: Megaphone });
    }
    return items;
});

// Everything captcha-related lives together: vendors, the pool controls and the algorithm tooling.
const captchaItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];
    if (canReadCaptcha.value && !isAgent.value) {
        items.push({ title: 'Captcha Providers', href: '/captcha-providers', icon: Shield });
    }
    if (isSuperAdmin.value) {
        items.push(
            { title: 'Captcha Control', href: '/captcha-control', icon: Cpu },
            { title: 'In-House Captcha', href: '/in-house-captcha', icon: Fingerprint },
            { title: 'Algorithm Monitor', href: '/captcha-algorithm-monitor', icon: ScanSearch },
        );
    }
    return items;
});

// IVAC protocol surface: the endpoint constants, origin recon and the request tester.
const ivacItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];
    if (isSuperAdmin.value) {
        items.push(
            { title: 'IVAC Endpoints', href: '/ivac-endpoints', icon: Waypoints },
            { title: 'Bypass IPs', href: '/bypass-ips', icon: Network },
        );
    }
    if (canAccessApiTester.value) {
        items.push({ title: 'API Tester', href: '/api-tester', icon: TerminalSquare });
    }
    return items;
});

// Bot fleet + the machines and builds behind it. Managers only get Bot Control
// (restricted to its Account Assignment tab); sub managers have no accounts.assign, so
// the whole section disappears for them.
const infrastructureItems = computed<NavItem[]>(() => {
    if (isSuperAdmin.value) {
        return [
            { title: 'Bot Control', href: '/bot-control', icon: Bot },
            { title: 'VPS Manager', href: '/vps-manager', icon: Globe },
            { title: 'APK Distribution', href: '/apk-manager', icon: Smartphone },
        ];
    }

    return canAssignAccounts.value
        ? [{ title: 'Bot Control', href: '/bot-control', icon: Bot }]
        : [];
});

const paymentItems = computed<NavItem[]>(() =>
    canReadAccounts.value ? [{ title: 'Payment Links', href: '/payment-links', icon: LinkIcon }] : [],
);

// Both OTP channels in one place — the Gmail watch and the ingested codes.
const messagingItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [];
    if (isSuperAdmin.value) {
        items.push({ title: 'Gmail', href: '/gmail', icon: Mail });
    }
    items.push({ title: 'OTPs', href: '/otps', icon: KeyRound });
    return items;
});

// Admins see every slot, managers only their own agents' rows.
const monitoringItems = computed<NavItem[]>(() =>
    canReadLogs.value ? [{ title: 'Log Analysis', href: '/log-analysis', icon: BarChart2 }] : [],
);

const downloadItems: NavItem[] = [
    { title: 'Android App', href: '/apk', icon: Smartphone },
    { title: 'Browser Extension', href: '/payment-helper', icon: Puzzle },
];

const sections = computed<NavSection[]>(() =>
    [
        { label: 'Overview', items: overviewItems.value },
        { label: 'Management', items: managementItems.value },
        { label: 'Captcha', items: captchaItems.value },
        { label: 'IVAC', items: ivacItems.value },
        { label: 'Infrastructure', items: infrastructureItems.value },
        { label: 'Payments', items: paymentItems.value },
        { label: 'Messaging', items: messagingItems.value },
        { label: 'Monitoring', items: monitoringItems.value },
        { label: 'Downloads', items: downloadItems, external: true },
    ].filter((section) => section.items.length > 0),
);

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-0">
            <template v-for="(section, index) in sections" :key="section.label">
                <SidebarSeparator v-if="index > 0" :class="separatorClass" />
                <SidebarGroup :class="groupClass">
                    <SidebarGroupLabel>{{ section.label }}</SidebarGroupLabel>
                    <SidebarMenu>
                        <SidebarMenuItem v-for="item in section.items" :key="item.title">
                            <SidebarMenuButton as-child :is-active="isCurrentUrl(item.href)" :tooltip="item.title">
                                <a v-if="section.external" :href="String(item.href)">
                                    <component :is="item.icon" :class="iconColorClass(item.title)" />
                                    <span class="font-semibold">{{ item.title }}</span>
                                </a>
                                <Link v-else :href="toUrl(item.href)" prefetch>
                                    <component :is="item.icon" :class="iconColorClass(item.title)" />
                                    <span class="font-semibold">{{ item.title }}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroup>
            </template>
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
