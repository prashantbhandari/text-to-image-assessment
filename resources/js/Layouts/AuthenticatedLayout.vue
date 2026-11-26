<script setup>
// App shell: floating nav, live credit balance, user menu, theme toggle.
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { formatNano } from '@/money';
import BrandMark from '@/Components/BrandMark.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const open = ref(false);
const initials = computed(() => user.value.name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase());

const links = computed(() => [
    { name: 'Create', href: route('generations.create'), active: route().current('generations.create') },
    { name: 'History', href: route('generations.index'), active: route().current('generations.index') },
    { name: 'Credits', href: route('credits.index'), active: route().current('credits.index') },
    ...(user.value.is_admin ? [{ name: 'Report', href: route('admin.report'), active: route().current('admin.report') }] : []),
]);
</script>

<template>
    <div class="page-bg min-h-screen bg-app text-fg">
        <nav class="sticky top-0 z-30 border-b border-line bg-app/75 backdrop-blur-xl">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                <div class="flex items-center gap-6">
                    <Link :href="route('generations.create')" class="flex items-center gap-2.5 font-semibold tracking-tight">
                        <BrandMark /> <span class="hidden sm:inline">Imagen</span>
                    </Link>
                    <div class="hidden items-center gap-1 rounded-xl border border-line bg-surface/70 p-1 sm:flex">
                        <Link v-for="l in links" :key="l.name" :href="l.href" class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition"
                            :class="l.active ? 'bg-accent text-accent-fg shadow' : 'text-muted hover:text-fg'">{{ l.name }}</Link>
                    </div>
                </div>

                <div class="hidden items-center gap-2 sm:flex">
                    <Link :href="route('credits.index')" class="flex items-center gap-2 rounded-xl border border-line bg-surface px-3 py-1.5 text-sm font-semibold tabular-nums transition hover:bg-surface-2" title="Credit balance">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>{{ formatNano(user.balance_nano) }}
                    </Link>
                    <ThemeToggle />
                    <Link :href="route('profile.edit')" class="grid h-9 w-9 place-items-center rounded-full bg-surface-2 text-xs font-bold ring-1 ring-line transition hover:ring-accent" :title="user.name">{{ initials }}</Link>
                    <Link :href="route('logout')" method="post" as="button" class="rounded-lg px-2 py-1.5 text-sm text-muted hover:text-fg">Log out</Link>
                </div>

                <div class="flex items-center gap-1 sm:hidden">
                    <ThemeToggle />
                    <button class="rounded-lg p-2 text-muted hover:bg-surface-2" aria-label="Menu" @click="open = !open">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="open ? 'M6 18L18 6M6 6l12 12' : 'M4 6h16M4 12h16M4 18h16'" /></svg>
                    </button>
                </div>
            </div>

            <div v-if="open" class="space-y-1 border-t border-line px-4 py-3 sm:hidden">
                <Link v-for="l in links" :key="l.name" :href="l.href" class="block rounded-lg px-3 py-2.5 text-sm font-medium" :class="l.active ? 'bg-accent text-accent-fg' : 'text-fg'">{{ l.name }}</Link>
                <div class="px-3 py-2 text-sm font-semibold tabular-nums">Balance {{ formatNano(user.balance_nano) }}</div>
                <Link :href="route('profile.edit')" class="block rounded-lg px-3 py-2.5 text-sm">Profile</Link>
                <Link :href="route('logout')" method="post" as="button" class="block w-full rounded-lg px-3 py-2.5 text-left text-sm">Log out</Link>
            </div>
        </nav>

        <header v-if="$slots.header" class="mx-auto max-w-6xl px-4 pt-10 sm:px-6"><slot name="header" /></header>
        <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6"><slot /></main>
    </div>
</template>
