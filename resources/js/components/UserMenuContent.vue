<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CircleUser, LogOut, Settings } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { toUrl } from '@/lib/utils';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { index as systemConfigIndex } from '@/routes/settings';
import type { User } from '@/types';

type Props = {
    user: User;
};

defineProps<Props>();

const page = usePage();
const isSuperAdmin = computed(() => (page.props.auth as any)?.permissions?.['bot.manage'] === true);
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuItem v-if="isSuperAdmin" :as-child="true">
        <Link class="block w-full cursor-pointer" :href="toUrl(systemConfigIndex())" prefetch>
            <Settings class="mr-2 h-4 w-4" />
            System settings
        </Link>
    </DropdownMenuItem>
    <DropdownMenuItem v-else :as-child="true">
        <Link class="block w-full cursor-pointer" :href="toUrl(editProfile())" prefetch>
            <CircleUser class="mr-2 h-4 w-4" />
            Profile settings
        </Link>
    </DropdownMenuItem>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link class="block w-full cursor-pointer" :href="toUrl(logout())" method="post" as="button"
            data-test="logout-button">
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
