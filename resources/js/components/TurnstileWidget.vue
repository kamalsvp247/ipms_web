<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    siteKey: string;
}>();

declare global {
    interface Window {
        turnstile?: {
            render: (el: HTMLElement, params: Record<string, unknown>) => string;
            remove: (id: string) => void;
        };
    }
}

const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

let scriptPromise: Promise<void> | null = null;

function loadScript(): Promise<void> {
    if (window.turnstile) {
        return Promise.resolve();
    }

    if (scriptPromise) {
        return scriptPromise;
    }

    scriptPromise = new Promise((resolve, reject) => {
        const existing = document.querySelector<HTMLScriptElement>('script[data-turnstile]');

        if (existing) {
            existing.addEventListener('load', () => resolve());
            return;
        }

        const script = document.createElement('script');
        script.src = SCRIPT_URL;
        script.async = true;
        script.defer = true;
        script.dataset.turnstile = 'true';
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Failed to load Turnstile'));
        document.head.appendChild(script);
    });

    return scriptPromise;
}

const container = ref<HTMLDivElement | null>(null);
let widgetId: string | null = null;

onMounted(async () => {
    try {
        await loadScript();
    } catch {
        return;
    }

    if (container.value && window.turnstile) {
        widgetId = window.turnstile.render(container.value, {
            sitekey: props.siteKey,
        });
    }
});

onBeforeUnmount(() => {
    if (widgetId && window.turnstile) {
        window.turnstile.remove(widgetId);
    }
});
</script>

<template>
    <div ref="container" />
</template>
