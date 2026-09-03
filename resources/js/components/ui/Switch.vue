<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    modelValue?: boolean;
    checked?: boolean;
    disabled?: boolean;
}>();

const emit = defineEmits(['update:modelValue', 'change']);

const isChecked = computed(() => props.modelValue ?? props.checked ?? false);

const toggle = () => {
    if (props.disabled) return;
    const newValue = !isChecked.value;
    emit('update:modelValue', newValue);
    emit('change', newValue);
};
</script>

<template>
    <button type="button" role="switch" :aria-checked="isChecked" :disabled="disabled" @click="toggle"
        class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50"
        :class="isChecked ? 'bg-primary' : 'bg-input'">
        <span aria-hidden="true"
            class="pointer-events-none block h-4 w-4 rounded-full bg-background shadow-lg ring-0 transition-transform"
            :class="isChecked ? 'translate-x-4' : 'translate-x-0'" />
    </button>
</template>
