<script setup>
// App shell: top nav, live credit balance, admin link (operators only).
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { formatNano } from '@/money';

const page = usePage();
const user = computed(() => page.props.auth.user);
const open = ref(false);

const links = computed(() => [
    { name: 'Generate', href: route('generations.create'), active: route().current('generations.create') },
    { name: 'History', href: route('generations.index'), active: route().current('generations.index') },
    { name: 'Credits', href: route('credits.index'), active: route().current('credits.index') },
    ...(user.value.is_admin
        ? [{ name: 'Admin report', href: route('admin.report'), active: route().current('admin.report') }]
        : []),
]);
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-900">
        <nav class="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
                <div class="flex items-center gap-8">
                    <Link :href="route('generations.create')" class="flex items-center gap-2 font-semibold tracking-tight">
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-indigo-600 text-white">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/></svg>
                        </span>
                        Imagen
                    </Link>
                    <div class="hidden gap-1 sm:flex">
                        <Link
                            v-for="l in links"
                            :key="l.name"
                            :href="l.href"
                            class="rounded-md px-3 py-2 text-sm font-medium transition-colors"
                            :class="l.active ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'"
                        >{{ l.name }}</Link>
                    </div>
                </div>

                <div class="hidden items-center gap-3 sm:flex">
                    <Link :href="route('credits.index')" class="rounded-full bg-emerald-50 px-3 py-1.5 text-sm font-semibold text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-100" title="Credit balance">
                        {{ formatNano(user.balance_nano) }}
                    </Link>
                    <Link :href="route('profile.edit')" class="text-sm text-slate-600 hover:text-slate-900">{{ user.name }}</Link>
                    <Link :href="route('logout')" method="post" as="button" class="text-sm text-slate-500 hover:text-slate-900">Log out</Link>
                </div>

                <button class="rounded-md p-2 text-slate-500 hover:bg-slate-100 sm:hidden" aria-label="Menu" @click="open = !open">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="open ? 'M6 18L18 6M6 6l12 12' : 'M4 6h16M4 12h16M4 18h16'" /></svg>
                </button>
            </div>

            <div v-if="open" class="space-y-1 border-t border-slate-200 px-4 py-3 sm:hidden">
                <Link v-for="l in links" :key="l.name" :href="l.href" class="block rounded-md px-3 py-2 text-sm font-medium" :class="l.active ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700'">{{ l.name }}</Link>
                <div class="px-3 py-2 text-sm font-semibold text-emerald-700">Balance {{ formatNano(user.balance_nano) }}</div>
                <Link :href="route('profile.edit')" class="block rounded-md px-3 py-2 text-sm text-slate-700">Profile</Link>
                <Link :href="route('logout')" method="post" as="button" class="block w-full rounded-md px-3 py-2 text-left text-sm text-slate-700">Log out</Link>
            </div>
        </nav>

        <header v-if="$slots.header" class="mx-auto max-w-6xl px-4 pt-8 sm:px-6">
            <slot name="header" />
        </header>

        <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6">
            <slot />
        </main>
    </div>
</template>
