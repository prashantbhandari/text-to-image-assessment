<script setup>
// Operator console shell: sidebar navigation (top bar on mobile), theme toggle, back-to-app link.
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import BrandMark from '@/Components/BrandMark.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import Toast from '@/Components/Toast.vue';

defineProps({ title: String, subtitle: String });

const user = computed(() => usePage().props.auth.user);
const open = ref(false);

// Icons are inline 24px stroke paths.
const items = computed(() => [
    { name: 'Report', href: route('admin.report'), on: route().current('admin.report'), d: 'M4 20V10m6 10V4m6 16v-7m4 7H2' },
    { name: 'Users', href: route('admin.users'), on: route().current('admin.users'), d: 'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zm13 10v-2a4 4 0 00-3-3.9M16 3.1a4 4 0 010 7.8' },
    { name: 'Webhooks', href: route('admin.webhooks'), on: route().current('admin.webhooks'), d: 'M13 2L3 14h8l-1 8 10-12h-8l1-8z' },
]);
</script>

<template>
    <div class="min-h-screen bg-app text-fg lg:grid lg:grid-cols-[250px_1fr]">
        <!-- Sidebar -->
        <aside class="border-b border-line bg-surface lg:sticky lg:top-0 lg:h-screen lg:border-b-0 lg:border-r">
            <div class="flex h-16 items-center justify-between px-5 lg:h-auto lg:flex-col lg:items-stretch lg:gap-8 lg:py-6">
                <Link :href="route('admin.report')" class="flex items-center gap-3 font-semibold">
                    <BrandMark /> <span>Imagen <span class="ml-1 rounded-md bg-accent/15 px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wider text-brand">Admin</span></span>
                </Link>
                <button class="rounded-lg p-2 text-muted hover:bg-surface-2 lg:hidden" aria-label="Menu" @click="open = !open">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="open ? 'M6 18L18 6M6 6l12 12' : 'M4 6h16M4 12h16M4 18h16'" /></svg>
                </button>
            </div>

            <nav :class="open ? 'block' : 'hidden lg:block'" class="px-3 pb-4 lg:flex lg:h-[calc(100%-6.5rem)] lg:flex-col lg:pb-6">
                <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-muted">Operations</p>
                <Link v-for="i in items" :key="i.name" :href="i.href" class="mb-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition"
                    :class="i.on ? 'bg-accent text-accent-fg shadow' : 'text-muted hover:bg-surface-2 hover:text-fg'">
                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="i.d" /></svg>
                    {{ i.name }}
                </Link>

                <div class="mt-4 space-y-1 border-t border-line pt-4 lg:mt-auto">
                    <Link :href="route('generations.create')" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-muted transition hover:bg-surface-2 hover:text-fg">
                        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5m6-6l-6 6 6 6" /></svg>
                        Back to app
                    </Link>
                    <div class="flex items-center justify-between px-3 py-2">
                        <span class="truncate text-xs text-muted" :title="user.email">{{ user.email }}</span>
                        <ThemeToggle />
                    </div>
                </div>
            </nav>
        </aside>

        <!-- Content -->
        <div class="page-bg min-w-0">
            <header class="px-5 pt-8 sm:px-8">
                <h1 class="text-3xl font-semibold sm:text-4xl">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1.5 max-w-3xl text-muted">{{ subtitle }}</p>
            </header>
            <main class="px-5 py-6 sm:px-8"><slot /></main>
        </div>
        <Toast />
    </div>
</template>
