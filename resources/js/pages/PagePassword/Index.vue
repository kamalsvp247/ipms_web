<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';

const props = defineProps<{
    redirectTo: string;
}>();

const form = useForm({
    password: '',
    redirect_to: props.redirectTo,
});

function submit() {
    form.post('/page-password', {
        onError: () => form.reset('password'),
    });
}
</script>

<template>
    <AuthBase title="Protected Page" description="Enter the page password to continue">
        <Head title="Protected Page" />

        <form @submit.prevent="submit" class="flex flex-col gap-1.5">
            <div class="grid gap-1.5">
                <Label for="password" class="text-[11px] font-semibold">Password</Label>
                <Input
                    id="password"
                    v-model="form.password"
                    type="password"
                    required
                    autofocus
                    autocomplete="off"
                    placeholder="Enter password"
                    class="h-8 text-[11px]"
                />
                <InputError :message="form.errors.password" />
            </div>

            <Button type="submit" class="w-full h-8 text-[11px]" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                Unlock
            </Button>
        </form>
    </AuthBase>
</template>
