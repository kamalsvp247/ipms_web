<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Megaphone } from 'lucide-vue-next';
import { computed } from 'vue';

/** Seconds of scroll per character — keeps the reading speed constant. */
const SECONDS_PER_CHAR = 0.14;
const MIN_DURATION_S = 12;

const page = usePage();

/** Shared from HandleInertiaRequests — already filtered to the enabled notices. */
const notices = computed(() => (page.props.notices as string[] | undefined) ?? []);

/** All live notices scroll as one track, separated by a bullet. */
const marqueeText = computed(() => notices.value.join('   •   '));

/** Longer notices scroll for longer, so the reading speed stays constant. */
const durationStyle = computed(() => ({
    animationDuration: `${Math.max(MIN_DURATION_S, marqueeText.value.length * SECONDS_PER_CHAR).toFixed(1)}s`,
}));
</script>

<template>
    <!-- Not dismissible on purpose: a live notice must stay on screen for everyone. -->
    <div
        v-if="marqueeText !== ''"
        class="sticky top-0 z-30 flex items-center gap-2 border-b border-red-200/70 bg-red-50/95 px-3 py-1.5 backdrop-blur-sm sm:gap-2.5 sm:px-6 dark:border-red-800/50 dark:bg-red-950/80"
    >
        <Megaphone class="h-3.5 w-3.5 shrink-0 text-red-600 dark:text-red-400" />

        <!-- The track holds the text twice so the loop has no visible seam. -->
        <div class="notice-marquee min-w-0 flex-1 overflow-hidden">
            <div class="notice-marquee__track" :style="durationStyle">
                <span class="font-bangla notice-marquee__item text-[16px] leading-relaxed text-red-600 dark:text-red-400">
                    {{ marqueeText }}
                </span>
                <span
                    aria-hidden="true"
                    class="font-bangla notice-marquee__item text-[16px] leading-relaxed text-red-600 dark:text-red-400"
                >
                    {{ marqueeText }}
                </span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.notice-marquee__track {
    display: flex;
    width: max-content;
    animation-name: notice-marquee-scroll;
    animation-timing-function: linear;
    animation-iteration-count: infinite;
}

.notice-marquee__item {
    padding-right: 3rem;
    white-space: nowrap;
}

.notice-marquee:hover .notice-marquee__track {
    animation-play-state: paused;
}

@keyframes notice-marquee-scroll {
    from {
        transform: translateX(0);
    }
    to {
        transform: translateX(-50%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .notice-marquee__track {
        animation: none;
    }
}
</style>
