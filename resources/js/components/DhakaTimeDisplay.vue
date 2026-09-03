<script setup lang="ts">
import { useDhakaTime } from '@/composables/useDhakaTime';

const { dhakaTime, windowCountdown, isWindowOpen } = useDhakaTime();
</script>

<template>
    <div class="flex items-center gap-3 sm:gap-6">
        <div class="text-center">
            <div class="text-[9px] text-zinc-400 sm:text-xs">Dhaka Time</div>
            <div class="font-mono text-xs font-bold tabular-nums text-neutral-900 dark:text-neutral-100 sm:text-base">
                {{ dhakaTime }}
            </div>
        </div>
        <div class="text-center">
            <div class="text-[9px] text-zinc-400 sm:text-xs">Window In</div>
            <div class="font-mono text-xs font-bold tabular-nums sm:text-base"
                :class="isWindowOpen ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">
                {{ windowCountdown }}
            </div>
        </div>
    </div>
</template>
