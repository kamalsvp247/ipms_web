import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

export function useDhakaTime() {
    const page = usePage();
    let interval: NodeJS.Timeout | null = null;

    const windowStartTime = computed(() => page.props.windowStartTime as string);
    const windowEndTime = computed(() => page.props.windowEndTime as string);

    // Server-synced clock: server sends its NTP timestamp; we compute the offset
    // between the server clock and the browser clock once at mount, then tick
    // using the browser's high-resolution timer to avoid OS clock drift.
    const serverTimestampMs = page.props.serverTimestampMs as number;
    let clockOffset = 0; // serverTime - browserTime at mount
    const nowMs = ref(serverTimestampMs);

    const utcNow = computed(() => new Date(nowMs.value));

    const dhakaTime = computed(() => {
        // Bangladesh Standard Time = UTC+6, no DST
        const d = new Date(utcNow.value.getTime() + 6 * 60 * 60 * 1000);
        const h = d.getUTCHours().toString().padStart(2, '0');
        const m = d.getUTCMinutes().toString().padStart(2, '0');
        const s = d.getUTCSeconds().toString().padStart(2, '0');
        return `${h}:${m}:${s}`;
    });

    const windowCountdown = computed(() => {
        if (!windowStartTime.value) return '';

        const dhakaNow = new Date(utcNow.value.getTime() + 6 * 60 * 60 * 1000);
        const [h, m, s] = windowStartTime.value.split(':').map(Number);

        const windowTime = new Date(dhakaNow);
        windowTime.setUTCHours(h, m, s, 0);

        const diff = windowTime.getTime() - dhakaNow.getTime();
        if (diff <= 0) {
            return 'OPEN';
        }

        const totalSec = Math.ceil(diff / 1000);
        const hh = Math.floor(totalSec / 3600);
        const mm = Math.floor((totalSec % 3600) / 60).toString().padStart(2, '0');
        const ss = (totalSec % 60).toString().padStart(2, '0');
        return hh > 0 ? `${hh}:${mm}:${ss}` : `${mm}:${ss}`;
    });

    const isWindowOpen = computed(() => windowCountdown.value === 'OPEN');

    onMounted(() => {
        clockOffset = serverTimestampMs - Date.now();
        interval = setInterval(() => {
            nowMs.value = Date.now() + clockOffset;
        }, 1000);
    });

    onUnmounted(() => {
        if (interval) clearInterval(interval);
    });

    return {
        dhakaTime,
        windowCountdown,
        isWindowOpen,
        windowStartTime,
        windowEndTime,
    };
}
