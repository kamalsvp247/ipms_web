<script setup lang="ts">
import * as THREE from 'three';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const canvasHost = ref<HTMLDivElement | null>(null);

let renderer: THREE.WebGLRenderer | null = null;
let animationFrame = 0;
let resizeObserver: ResizeObserver | null = null;

// IVAC API endpoints paired with a representative status code — shown as
// floating badges drifting up through the wave instead of plain particles.
const apiPulses = [
    { status: 200, endpoint: '/auth/v23-sign-in' },
    { status: 200, endpoint: '/forgot-password/sendOtp' },
    { status: 200, endpoint: '/otp/verifySigninOtp' },
    { status: 200, endpoint: '/file/upload_file_v23' },
    { status: 200, endpoint: '/appointment/appointment-booking-config' },
    { status: 200, endpoint: '/appointment/get-booking-config' },
    { status: 200, endpoint: '/slots/{reserveSlotId}/reserve-slot' },
    { status: 200, endpoint: '/payment/{paymentConfigId}/dg-epay/initiate' },
    { status: 429, endpoint: '/slots/{reserveSlotId}/reserve-slot' },
    { status: 401, endpoint: '/otp/verifySigninOtp' },
];

const badges = apiPulses.map((pulse, i) => ({
    ...pulse,
    left: 4 + ((i * 9.7) % 92),
    duration: 22 + ((i * 3) % 10),
    delay: -(i * 3.4),
}));

onMounted(() => {
    const host = canvasHost.value;
    if (!host) {
        return;
    }

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(55, host.clientWidth / host.clientHeight, 0.1, 100);
    camera.position.set(0, 5, 11);
    camera.lookAt(0, 0, 0);

    renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setSize(host.clientWidth, host.clientHeight);
    host.appendChild(renderer.domElement);
    host.style.setProperty('--rise', host.clientHeight + 60 + 'px');

    // Wireframe ocean grid — waves driven by a sine field on the Y axis.
    const gridSize = 26;
    const gridSegments = 44;
    const waveGeometry = new THREE.PlaneGeometry(gridSize, gridSize, gridSegments, gridSegments);
    waveGeometry.rotateX(-Math.PI / 2.35);
    const waveMaterial = new THREE.MeshBasicMaterial({
        color: 0x71717a,
        wireframe: true,
        transparent: true,
        opacity: 0.3,
    });
    const wave = new THREE.Mesh(waveGeometry, waveMaterial);
    wave.position.y = -1.5;
    scene.add(wave);

    const basePositions = waveGeometry.attributes.position.array.slice();

    const clock = new THREE.Clock();

    const animate = () => {
        const elapsed = clock.getElapsedTime();

        const positions = waveGeometry.attributes.position;
        for (let i = 0; i < positions.count; i++) {
            const x = basePositions[i * 3];
            const z = basePositions[i * 3 + 2];
            const y = Math.sin(x * 0.4 + elapsed * 0.6) * 0.35 + Math.cos(z * 0.35 + elapsed * 0.5) * 0.35;

            // Ripple emanating from the logo/video anchored at the grid origin —
            // makes the wave read as reacting to the badge sitting on top of it.
            const dist = Math.sqrt(x * x + z * z);
            const ripple = Math.sin(dist * 1.4 - elapsed * 1.8) * 0.22 * Math.exp(-dist * 0.18);

            positions.setY(i, y + ripple);
        }
        positions.needsUpdate = true;
        wave.rotation.z = Math.sin(elapsed * 0.05) * 0.03;

        camera.position.x = Math.sin(elapsed * 0.08) * 1.2;
        camera.lookAt(0, 0, 0);

        renderer?.render(scene, camera);
        animationFrame = requestAnimationFrame(animate);
    };
    animate();

    resizeObserver = new ResizeObserver(() => {
        if (!renderer || !host) {
            return;
        }
        camera.aspect = host.clientWidth / host.clientHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(host.clientWidth, host.clientHeight);
        host.style.setProperty('--rise', host.clientHeight + 60 + 'px');
    });
    resizeObserver.observe(host);

    onBeforeUnmount(() => {
        cancelAnimationFrame(animationFrame);
        resizeObserver?.disconnect();
        waveGeometry.dispose();
        waveMaterial.dispose();
        renderer?.dispose();
        renderer?.domElement.remove();
        renderer = null;
    });
});
</script>

<template>
    <div ref="canvasHost" class="absolute inset-0 overflow-hidden">
        <span
            v-for="(badge, i) in badges"
            :key="i"
            class="api-badge absolute bottom-0 flex items-center gap-1.5 border border-zinc-500/30 bg-zinc-500/5 px-1.5 py-0.5 font-mono text-[10px] whitespace-nowrap text-zinc-500/70 backdrop-blur-[1px] dark:border-zinc-400/25 dark:text-zinc-400/70"
            :style="{
                left: badge.left + '%',
                animationDuration: badge.duration + 's',
                animationDelay: badge.delay + 's',
            }"
        >
            <span
                class="font-semibold"
                :class="{
                    'text-emerald-600 dark:text-emerald-400': badge.status < 400,
                    'text-orange-600 dark:text-orange-400': badge.status >= 400 && badge.status < 500,
                    'text-red-600 dark:text-red-400': badge.status >= 500,
                }"
                >{{ badge.status }}</span
            >
            <span class="font-bold text-black dark:text-white">{{ badge.endpoint }}</span>
        </span>
    </div>
</template>

<style scoped>
.api-badge {
    animation-name: float-up;
    animation-timing-function: linear;
    animation-iteration-count: infinite;
}

@keyframes float-up {
    0% {
        transform: translateY(0);
        opacity: 0;
    }
    10% {
        opacity: 0.8;
    }
    90% {
        opacity: 0.8;
    }
    100% {
        transform: translateY(calc(-1 * var(--rise, 500px)));
        opacity: 0;
    }
}
</style>
