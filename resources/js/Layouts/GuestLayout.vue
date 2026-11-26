<script setup>
// Layout for login / register / password screens: brand panel + form card.
import BrandMark from '@/Components/BrandMark.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="page-bg grid min-h-screen bg-app lg:grid-cols-2">
        <!-- Brand panel (desktop) -->
        <aside class="relative hidden overflow-hidden border-r border-line bg-surface-2 p-12 lg:flex lg:flex-col lg:justify-between">
            <div class="pointer-events-none absolute -left-32 -top-32 h-[30rem] w-[30rem] rounded-full bg-accent/20 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-40 right-0 h-[26rem] w-[26rem] rounded-full bg-accent/10 blur-3xl"></div>
            <Link href="/" class="relative flex items-center gap-3 text-lg font-semibold"><BrandMark /> Imagen</Link>
            <div class="relative">
                <h2 class="display font-display text-5xl font-semibold leading-[1.05]">Describe it.<br />Develop it.</h2>
                <p class="mt-4 max-w-sm text-muted">Turn a sentence into an image. See the price before you commit, and never pay for a failed result.</p>
                <ul class="mt-8 space-y-3 text-sm">
                    <li v-for="t in ['Live cost estimate before you submit', 'Failed generations are always free', 'Every charge itemised in your history']" :key="t" class="flex items-center gap-3">
                        <span class="grid h-5 w-5 place-items-center rounded-full bg-accent/15 text-brand"><svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg></span>{{ t }}
                    </li>
                </ul>
            </div>
            <p class="relative text-xs text-muted">© Imagen</p>
        </aside>

        <!-- Form -->
        <main class="relative flex flex-col items-center justify-center px-4 py-10">
            <div class="absolute right-4 top-4"><ThemeToggle /></div>
            <Link href="/" class="mb-8 flex items-center gap-3 text-lg font-semibold lg:hidden"><BrandMark /> Imagen</Link>
            <div class="card fade-up w-full max-w-md p-7">
                <slot />
            </div>
        </main>
    </div>
</template>
