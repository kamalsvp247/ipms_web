<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import NoticeBanner from '@/components/NoticeBanner.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
    fullWidth?: boolean;
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    fullWidth: false,
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <!-- No overflow clipping on the inset itself: it would turn the inset into the
             nearest scroll container and the sticky notice banner would stop sticking. -->
        <AppContent variant="sidebar">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <NoticeBanner />
            <div data-slot="page-content" :class="['overflow-x-hidden', fullWidth ? 'w-full' : 'mx-auto w-full max-w-6xl']">
                <slot />
            </div>
        </AppContent>
    </AppShell>
</template>
