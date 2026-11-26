<script setup>
// Credits: balance hero, "buy credits" packs (fake purchase) and the ledger.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatNano } from '@/money';

defineProps({ packs: Array, ledger: Array });

const user = computed(() => usePage().props.auth.user);
const busy = ref(null);

const buy = (amount) => {
    busy.value = amount;
    router.post(route('credits.purchase'), { amount }, { preserveScroll: true, onFinish: () => (busy.value = null) });
};
</script>

<template>
    <Head title="Credits" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-4xl font-semibold sm:text-5xl">Your <span class="italic text-brand">credits</span></h1>
            <p class="mt-2 text-muted">Credits are US dollars. This is a demo, so purchases are instant and no payment is taken.</p>
        </template>

        <section class="card relative overflow-hidden p-7">
            <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-accent/20 blur-3xl"></div>
            <p class="relative text-xs font-semibold uppercase tracking-wider text-muted">Current balance</p>
            <p class="font-display relative mt-1 text-6xl font-semibold tabular-nums">{{ formatNano(user.balance_nano) }}</p>

            <h2 class="relative mt-8 text-sm font-semibold">Add credits</h2>
            <div class="relative mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <button v-for="p in packs" :key="p" :disabled="busy !== null" class="group rounded-2xl border border-line bg-surface p-4 text-left transition hover:border-accent hover:bg-accent/5 disabled:opacity-50" @click="buy(p)">
                    <span class="font-display text-3xl font-semibold">${{ p }}</span>
                    <span class="mt-1 block text-xs text-muted">{{ busy === p ? 'Adding…' : 'Add instantly' }}</span>
                </button>
            </div>
        </section>

        <section class="card mt-6 overflow-hidden">
            <h2 class="border-b border-line px-5 py-4 text-sm font-semibold">Activity</h2>
            <p v-if="!ledger.length" class="p-8 text-center text-sm text-muted">No activity yet. Add credits to get started.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted"><tr><th class="px-5 py-2.5 font-medium">When</th><th class="px-5 py-2.5 font-medium">What</th><th class="px-5 py-2.5 text-right font-medium">Amount</th><th class="px-5 py-2.5 text-right font-medium">Balance</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="e in ledger" :key="e.id" class="transition hover:bg-surface-2">
                            <td class="px-5 py-3 text-muted">{{ new Date(e.created_at).toLocaleString() }}</td>
                            <td class="px-5 py-3">{{ e.type === 'charge' ? 'Image generation' : 'Credit purchase' }}</td>
                            <td class="px-5 py-3 text-right font-semibold tabular-nums" :class="e.amount_nano >= 0 ? 'text-emerald-600 dark:text-emerald-400' : ''">{{ e.amount_nano >= 0 ? '+' : '' }}{{ formatNano(e.amount_nano, 6) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-muted">{{ formatNano(e.balance_after_nano, 6) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AuthenticatedLayout>
</template>
