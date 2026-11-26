<script setup>
// Credits page: balance, fake "Buy credits" packs and the ledger.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatNano } from '@/money';

defineProps({ packs: Array, ledger: Array });

const user = computed(() => usePage().props.auth.user);
const busy = ref(false);

const buy = (amount) => {
    busy.value = true;
    router.post(route('credits.purchase'), { amount }, { preserveScroll: true, onFinish: () => (busy.value = false) });
};
</script>

<template>
    <Head title="Credits" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-2xl font-semibold tracking-tight">Credits</h1>
            <p class="mt-1 text-sm text-slate-500">Credits are US dollars. This is a demo: purchases are instant and no payment is taken.</p>
        </template>

        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm text-slate-500">Current balance</p>
            <p class="text-4xl font-semibold tracking-tight">{{ formatNano(user.balance_nano) }}</p>
            <h2 class="mt-6 text-sm font-medium">Buy credits</h2>
            <div class="mt-2 flex flex-wrap gap-3">
                <button v-for="p in packs" :key="p" :disabled="busy" class="rounded-lg border border-indigo-200 bg-indigo-50 px-5 py-2.5 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-100 disabled:opacity-50" @click="buy(p)">
                    + ${{ p }}
                </button>
            </div>
        </section>

        <section class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <h2 class="border-b border-slate-100 px-5 py-3 text-sm font-medium">Activity</h2>
            <p v-if="!ledger.length" class="p-6 text-sm text-slate-500">No activity yet.</p>
            <table v-else class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs text-slate-500"><tr><th class="px-5 py-2">When</th><th class="px-5 py-2">Type</th><th class="px-5 py-2 text-right">Amount</th><th class="px-5 py-2 text-right">Balance</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="e in ledger" :key="e.id">
                        <td class="px-5 py-2 text-slate-600">{{ new Date(e.created_at).toLocaleString() }}</td>
                        <td class="px-5 py-2 capitalize">{{ e.type === 'charge' ? 'Image generation' : 'Purchase' }}</td>
                        <td class="px-5 py-2 text-right font-medium tabular-nums" :class="e.amount_nano >= 0 ? 'text-emerald-700' : 'text-slate-900'">{{ e.amount_nano >= 0 ? '+' : '' }}{{ formatNano(e.amount_nano, 6) }}</td>
                        <td class="px-5 py-2 text-right tabular-nums text-slate-600">{{ formatNano(e.balance_after_nano, 6) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </AuthenticatedLayout>
</template>
