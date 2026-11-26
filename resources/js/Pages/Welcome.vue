<script setup>
// Public landing page.
import { Head, Link } from '@inertiajs/vue3';

defineProps({ canLogin: Boolean, canRegister: Boolean });
</script>

<template>
    <Head title="Welcome" />
    <div class="grid min-h-screen place-items-center bg-gradient-to-b from-indigo-50 to-white px-6 text-center">
        <div class="max-w-xl">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-indigo-600 text-white">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z" /></svg>
            </span>
            <h1 class="mt-6 text-4xl font-semibold tracking-tight text-slate-900">Turn words into images</h1>
            <p class="mt-3 text-slate-600">Pay only for what you generate. See the price before you submit, and failed generations are always free.</p>
            <div class="mt-8 flex justify-center gap-3">
                <template v-if="$page.props.auth.user">
                    <Link :href="route('generations.create')" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">Open app</Link>
                </template>
                <template v-else>
                    <Link v-if="canRegister" :href="route('register')" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">Get started</Link>
                    <Link v-if="canLogin" :href="route('login')" class="rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Log in</Link>
                </template>
            </div>
        </div>
    </div>
</template>
