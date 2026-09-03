<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { index as systemConfigIndex } from '@/routes/settings';
import { edit as editPassword } from '@/routes/user-password';
import { type NavItem } from '@/types';

const page = usePage();
const isSuperAdmin = computed(() => (page.props.auth as any)?.permissions?.['bot.manage'] === true);

const baseNavItems: NavItem[] = [
    { title: 'Profile', href: editProfile() },
    { title: 'Password', href: editPassword() },
    { title: 'Appearance', href: editAppearance() },
];

const sidebarNavItems = computed(() =>
    isSuperAdmin.value
        ? [...baseNavItems, { title: 'System Config', href: systemConfigIndex() }]
        : baseNavItems,
);

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <div class="w-full max-w-[90rem] px-3 sm:px-4 py-4 sm:py-6">
        <Heading title="Settings" description="Manage your profile and account settings" />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav class="flex flex-col space-y-1 space-x-0" aria-label="Settings">
                    <Button v-for="item in sidebarNavItems" :key="toUrl(item.href)" variant="ghost" :class="[
                        'w-full justify-start',
                        { 'bg-muted': isCurrentUrl(item.href) },
                    ]" as-child>
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1 min-w-0">
                <section class="w-full space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
