<script setup>
// Public landing page.
import { Head, Link } from '@inertiajs/vue3';
import BrandMark from '@/Components/BrandMark.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';

defineProps({ canLogin: Boolean, canRegister: Boolean });

// Abstract "prints" so the hero shows what the product makes without needing real images.
const prints = [
    { g: 'from-amber-300 via-orange-500 to-rose-700', cap: 'Golden hour, 35mm', r: 'aspect-[3/4]' },
    { g: 'from-sky-300 via-cyan-600 to-slate-900', cap: 'Harbour at dawn', r: 'aspect-square' },
    { g: 'from-emerald-300 via-teal-600 to-stone-900', cap: 'Forest, soft fog', r: 'aspect-[3/4]' },
];
const steps = [
    ['01', 'Describe', 'Write a prompt, choose a size and quality. Add reference images if you have them.'],
    ['02', 'Check the price', 'A live estimate with a likely range, before anything is submitted.'],
    ['03', 'Develop', 'Watch it go from queued to done. You are charged the exact final cost, or nothing if it fails.'],
];
</script>

<template>
    <Head title="Welcome" />
    <div class="page-bg min-h-screen overflow-hidden bg-app text-fg">
        <header class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5">
            <span class="flex items-center gap-2.5 text-lg font-semibold tracking-tight"><BrandMark /> Imagen</span>
            <div class="flex items-center gap-2">
                <ThemeToggle />
                <Link v-if="$page.props.auth.user" :href="route('generations.create')" class="btn-accent">Open app</Link>
                <template v-else>
                    <Link v-if="canLogin" :href="route('login')" class="rounded-lg px-4 py-2 text-sm font-medium text-muted hover:text-fg">Log in</Link>
                    <Link v-if="canRegister" :href="route('register')" class="btn-accent hidden sm:inline-flex">Get started</Link>
                </template>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-6 pb-24 pt-10 sm:pt-16">
            <section class="grid items-center gap-14 lg:grid-cols-[1.1fr_1fr]">
                <div class="fade-up">
                    <span class="inline-flex items-center gap-2 rounded-full border border-line bg-surface/70 px-3 py-1 text-xs font-medium text-muted">
                        <span class="h-1.5 w-1.5 rounded-full bg-accent"></span> Pay only for images that succeed
                    </span>
                    <h1 class="mt-6 text-5xl font-semibold leading-[1.02] sm:text-7xl">
                        Words in.<br /><span class="text-brand italic">Pictures</span> out.
                    </h1>
                    <p class="mt-6 max-w-lg text-lg leading-relaxed text-muted">A fast, honest image generator. See the cost before you commit, track every image from queue to finish, and never pay for a failure.</p>
                    <div v-if="!$page.props.auth.user" class="mt-9 flex flex-wrap gap-3">
                        <Link v-if="canRegister" :href="route('register')" class="btn-accent px-6 py-3">Start creating</Link>
                        <Link v-if="canLogin" :href="route('login')" class="btn-ghost px-6 py-3">Log in</Link>
                    </div>
                </div>

                <!-- stacked "contact sheet" -->
                <div class="relative mx-auto grid w-full max-w-md grid-cols-2 gap-4" aria-hidden="true">
                    <div class="pointer-events-none absolute -inset-10 rounded-full bg-accent/15 blur-3xl"></div>
                    <figure v-for="(p, i) in prints" :key="p.cap" class="card relative flex flex-col overflow-hidden p-2" :class="i === 0 ? 'row-span-2' : ''" :style="`transform: rotate(${[-2, 2, 1.5][i]}deg)`">
                        <div class="w-full rounded-xl bg-gradient-to-br" :class="[p.g, i === 0 ? 'min-h-0 flex-1' : p.r]"></div>
                        <figcaption class="px-1 pb-0.5 pt-2 text-xs text-muted">{{ p.cap }}</figcaption>
                    </figure>
                </div>
            </section>

            <section class="mt-28 grid gap-4 md:grid-cols-3">
                <div v-for="[n, t, d] in steps" :key="n" class="card p-6">
                    <span class="font-display text-3xl text-brand">{{ n }}</span>
                    <h3 class="mt-3 text-lg font-semibold">{{ t }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-muted">{{ d }}</p>
                </div>
            </section>
        </main>
    </div>
</template>
