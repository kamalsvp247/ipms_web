<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import TurnstileWidget from '@/components/TurnstileWidget.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthSplitLayout from '@/layouts/auth/AuthSplitLayout.vue';
import { store as loginRoute } from '@/routes/login';
import { store } from '@/routes/register';

defineProps<{
    turnstileSiteKey?: string;
}>();

// Lets a manager share a link like /register?ref=CODE that arrives pre-filled.
const referralCode = new URLSearchParams(window.location.search).get('ref') ?? '';
</script>

<template>
    <AuthSplitLayout
        title="Create an account"
        description="Fill in your details to request access"
    >
        <Head title="Register" />

        <Form
            v-bind="store.form()"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="name">Full name</Label>
                    <Input
                        id="name"
                        type="text"
                        name="name"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="name"
                        placeholder="Your name"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        :tabindex="2"
                        autocomplete="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="phone">Phone number</Label>
                    <Input
                        id="phone"
                        type="tel"
                        name="phone"
                        required
                        :tabindex="3"
                        autocomplete="tel"
                        placeholder="01XXXXXXXXX"
                    />
                    <InputError :message="errors.phone" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        required
                        :tabindex="4"
                        autocomplete="new-password"
                        placeholder="••••••••"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        required
                        :tabindex="5"
                        autocomplete="new-password"
                        placeholder="••••••••"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <div class="grid gap-2">
                    <Label for="referral_code">Referral code</Label>
                    <Input
                        id="referral_code"
                        type="text"
                        name="referral_code"
                        required
                        :tabindex="6"
                        autocomplete="off"
                        placeholder="e.g. JEWEL-4X9K"
                        :default-value="referralCode"
                    />
                    <InputError :message="errors.referral_code" />
                </div>

                <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:border-amber-800/40 dark:bg-amber-950/30 dark:text-amber-400">
                    Your account will be reviewed by an admin before you can log in.
                </div>

                <div v-if="turnstileSiteKey" class="grid gap-2">
                    <TurnstileWidget :site-key="turnstileSiteKey" />
                    <InputError :message="errors['cf-turnstile-response']" />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full"
                    :tabindex="7"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    Request Access
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Already have an account?
                <TextLink :href="loginRoute.url()" :tabindex="8">Log in</TextLink>
            </div>
        </Form>
    </AuthSplitLayout>
</template>
