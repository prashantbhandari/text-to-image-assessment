<script setup>
// Operator report: credit spend vs provider cost vs profit, by day and by user.
// Provider cost per day is checked against Pixelforge's own GET /v1/usage.
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatNano as fmt } from '@/money';

// Admin figures are shown exactly (9 decimals) so they can be compared with Pixelforge's /v1/usage.
const formatNano = (n) => fmt(n, 9);

const props = defineProps({
    per_day: Array,
    per_user: Array,
    per_day_user: Array,
    totals: Object,
    reconciliation: Object,
});

const tab = ref('day');
const tabs = [['day', 'By day'], ['user', 'By user'], ['both', 'Day × user']];
const rec = (day) => props.reconciliation?.days?.[day];
const margin = computed(() => (props.totals.charged_nano ? ((props.totals.profit_nano / props.totals.charged_nano) * 100).toFixed(1) : '0.0'));
</script>

<template>
    <Head title="Admin report" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-2xl font-semibold tracking-tight">Admin report</h1>
            <p class="mt-1 text-sm text-slate-500">Succeeded generations only, bucketed by the UTC day they completed (same as Pixelforge's usage report).</p>
        </template>

        <div class="grid gap-4 sm:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs text-slate-500">Credit spend (charged)</p><p class="text-2xl font-semibold">{{ formatNano(totals.charged_nano) }}</p></div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs text-slate-500">Provider cost</p><p class="text-2xl font-semibold">{{ formatNano(totals.cost_nano) }}</p></div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs text-slate-500">Profit</p><p class="text-2xl font-semibold text-emerald-700">{{ formatNano(totals.profit_nano) }}</p><p class="text-xs text-slate-500">{{ margin }}% margin</p></div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs text-slate-500">Generations billed</p><p class="text-2xl font-semibold">{{ totals.generations }}</p></div>
        </div>

        <p v-if="reconciliation === null" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Could not reach Pixelforge /v1/usage to reconcile.</p>
        <p v-else-if="reconciliation.matches" class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">✓ Provider costs match Pixelforge's /v1/usage for every day (total {{ formatNano(reconciliation.total_nano) }}).</p>
        <p v-else class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-800">⚠ Some days differ from Pixelforge's /v1/usage. Mismatched days are flagged below.</p>

        <div class="mt-6 flex gap-1 rounded-lg bg-slate-100 p-1 text-sm sm:w-fit">
            <button v-for="[k, label] in tabs" :key="k" class="rounded-md px-4 py-1.5 font-medium" :class="tab === k ? 'bg-white shadow-sm' : 'text-slate-600'" @click="tab = k">{{ label }}</button>
        </div>

        <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm tabular-nums">
                <thead class="bg-slate-50 text-left text-xs text-slate-500">
                    <tr>
                        <th v-if="tab !== 'user'" class="px-4 py-2">Day (UTC)</th>
                        <th v-if="tab !== 'day'" class="px-4 py-2">User</th>
                        <th class="px-4 py-2 text-right">Gens</th>
                        <th class="px-4 py-2 text-right">Charged</th>
                        <th class="px-4 py-2 text-right">Provider cost</th>
                        <th class="px-4 py-2 text-right">Profit</th>
                        <th v-if="tab === 'day'" class="px-4 py-2 text-right">Pixelforge /usage</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="(r, i) in tab === 'day' ? per_day : tab === 'user' ? per_user : per_day_user" :key="i">
                        <td v-if="tab !== 'user'" class="px-4 py-2">{{ r.day }}</td>
                        <td v-if="tab !== 'day'" class="px-4 py-2">{{ r.name }} <span class="text-xs text-slate-500">{{ r.email }}</span></td>
                        <td class="px-4 py-2 text-right">{{ r.generations }}</td>
                        <td class="px-4 py-2 text-right">{{ formatNano(r.charged_nano) }}</td>
                        <td class="px-4 py-2 text-right">{{ formatNano(r.cost_nano) }}</td>
                        <td class="px-4 py-2 text-right text-emerald-700">{{ formatNano(r.profit_nano) }}</td>
                        <td v-if="tab === 'day'" class="px-4 py-2 text-right">
                            <template v-if="rec(r.day)">{{ formatNano(rec(r.day).provider_nano) }} <span :class="rec(r.day).match ? 'text-emerald-600' : 'font-bold text-red-600'">{{ rec(r.day).match ? '✓' : '✗' }}</span></template>
                            <span v-else class="text-slate-400">—</span>
                        </td>
                    </tr>
                    <tr v-if="!(tab === 'day' ? per_day : tab === 'user' ? per_user : per_day_user).length"><td colspan="7" class="p-6 text-center text-slate-500">No billed generations yet.</td></tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
