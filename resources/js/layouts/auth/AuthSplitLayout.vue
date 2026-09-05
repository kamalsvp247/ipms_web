<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import CyberOceanBackground from '@/components/CyberOceanBackground.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
    >
        <div
            class="relative hidden h-full flex-col bg-zinc-200 p-10 text-zinc-900 lg:flex dark:border-r overflow-hidden"
        >
            <!-- Sci-fi HUD grid overlay -->
            <div
                class="absolute inset-0"
                style="background-image: linear-gradient(rgba(113,113,122,0.10) 1px, transparent 1px), linear-gradient(90deg, rgba(113,113,122,0.10) 1px, transparent 1px); background-size: 32px 32px;"
            />
            <!-- Ambient 3D ocean/particle animation, layered on top of the video -->
            <CyberOceanBackground class="opacity-80" />
            <Link :href="home()" class="relative z-20 inline-block">
                <img
                    src="/images/duronto-logo.svg"
                    alt="Duronto"
                    class="h-16 w-16 rounded-lg object-contain"
                />
            </Link>
            <div class="relative z-20 mt-auto">
                <blockquote class="space-y-2">
                    <p class="text-lg font-light leading-snug text-zinc-600">
                        "Automate your IVAC visa appointment booking — faster, smarter, reliable."
                    </p>
                </blockquote>
            </div>
        </div>
        <div class="lg:p-8">
            <div
                class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[21.875rem]"
            >
                <div class="flex flex-col items-center space-y-2 text-center">
                    <!-- Intro video, positioned above the title -->
                    <video
                        class="h-20 w-20 rounded-2xl border border-zinc-500/40 object-cover mix-blend-multiply dark:border-zinc-400/30 dark:mix-blend-normal"
                        src="/videos/duronto-intro.mp4"
                        autoplay
                        muted
                        loop
                        playsinline
                    />
                    <h1 class="text-xl font-medium tracking-tight" v-if="title">
                        {{ title }}
                    </h1>
                    <p class="text-sm text-muted-foreground" v-if="description">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
