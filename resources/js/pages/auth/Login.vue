<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Puzzle, Smartphone } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import TurnstileWidget from '@/components/TurnstileWidget.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthSplitLayout from '@/layouts/auth/AuthSplitLayout.vue';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { store as registerRoute } from '@/routes/register';

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
    turnstileSiteKey?: string;
}>();
</script>

<template>
    <AuthSplitLayout
        title="Log in to your account"
        description="Enter your email and password below to log in"
    >
        <Head title="Log in" />

        <div
            v-if="status && !status.includes('pending')"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            {{ status }}
        </div>
        <div
            v-if="status && status.includes('pending')"
            class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-center text-sm text-amber-700 dark:border-amber-800/40 dark:bg-amber-950/30 dark:text-amber-400"
        >
            {{ status }}
        </div>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Password</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm"
                            :tabindex="5"
                        >
                            Forgot password?
                        </TextLink>
                    </div>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" name="remember" :tabindex="3" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <div v-if="turnstileSiteKey" class="grid gap-2">
                    <TurnstileWidget :site-key="turnstileSiteKey" />
                    <InputError :message="errors['cf-turnstile-response']" />
                </div>

                <Button
                    type="submit"
                    class="mt-4 w-full"
                    :tabindex="4"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    Log in
                </Button>
            </div>

            <div v-if="canRegister" class="text-center text-sm text-muted-foreground">
                Don't have an account?
                <TextLink :href="registerRoute.url()" :tabindex="6">Request access</TextLink>
            </div>
        </Form>

        <div class="mt-6 border-t border-border pt-4">
            <p class="mb-3 text-center text-xs font-medium uppercase tracking-widest text-muted-foreground">Downloads</p>
            <div class="grid grid-cols-2 gap-3">
                <a
                    href="/payment-helper"
                    class="flex items-center justify-center gap-2 rounded-lg border border-border px-3 py-2.5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                >
                    <Puzzle class="h-4 w-4 shrink-0" /> Extension
                </a>
                <a
                    href="/apk"
                    class="flex items-center justify-center gap-2 rounded-lg border border-border px-3 py-2.5 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                >
                    <Smartphone class="h-4 w-4 shrink-0" /> APK
                </a>
            </div>
        </div>
    </AuthSplitLayout>
</template>
