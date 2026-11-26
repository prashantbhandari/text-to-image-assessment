<script setup>
// Operator report: credit spend vs provider cost vs profit, by day and by user.
// Provider cost per day is checked against Pixelforge's own GET /v1/usage.
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatNano as fmt } from '@/money';

// Admin figures are shown exactly (9 decimals) so they can be compared with /v1/usage.
const formatNano = (n) => fmt(n, 9);

const props = defineProps({ per_day: Array, per_user: Array, per_day_user: Array, totals: Object, reconciliation: Object });

const tab = ref('day');
const tabs = [['day', 'By day'], ['user', 'By user'], ['both', 'Day × user']];
const rows = computed(() => (tab.value === 'day' ? props.per_day : tab.value === 'user' ? props.per_user : props.per_day_user));
const rec = (day) => props.reconciliation?.days?.[day];
const margin = computed(() => (props.totals.charged_nano ? ((props.totals.profit_nano / props.totals.charged_nano) * 100).toFixed(1) : '0.0'));

// Bars: charged vs provider cost per day, oldest -> newest (last 14 days with data).
const chart = computed(() => {
    const days = [...props.per_day].reverse().slice(-14);
    const max = Math.max(1, ...days.map((d) => d.charged_nano));
    return days.map((d) => ({ ...d, c: (d.charged_nano / max) * 100, p: (d.cost_nano / max) * 100 }));
});

const stats = computed(() => [
    ['Credit spend', formatNano(props.totals.charged_nano), 'Charged to users'],
    ['Provider cost', formatNano(props.totals.cost_nano), 'Billed by Pixelforge'],
    ['Profit', formatNano(props.totals.profit_nano), `${margin.value}% margin`],
    ['Generations', String(props.totals.generations), 'Billed (succeeded)'],
]);
</script>

<template>
    <Head title="Report" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-4xl font-semibold sm:text-5xl">Operator <span class="italic text-brand">report</span></h1>
            <p class="mt-2 max-w-2xl text-muted">Succeeded generations only, bucketed by the UTC day they completed: the same rule Pixelforge's usage report uses.</p>
        </template>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="([label, value, sub], i) in stats" :key="label" class="card p-5" :class="i === 2 ? 'ring-1 ring-accent/40' : ''">
                <p class="text-xs font-semibold uppercase tracking-wider text-muted">{{ label }}</p>
                <p class="font-display mt-1 truncate text-2xl font-semibold tabular-nums" :class="i === 2 ? 'text-emerald-600 dark:text-emerald-400' : ''" :title="value">{{ value }}</p>
                <p class="mt-0.5 text-xs text-muted">{{ sub }}</p>
            </div>
        </div>

        <p v-if="reconciliation === null" class="mt-4 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-300">Could not reach Pixelforge /v1/usage to reconcile.</p>
        <p v-else-if="reconciliation.matches" class="mt-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-800 dark:text-emerald-300">✓ Provider costs match Pixelforge's /v1/usage for every day (total {{ formatNano(reconciliation.total_nano) }}).</p>
        <p v-else class="mt-4 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-800 dark:text-red-300">⚠ Some days differ from Pixelforge's /v1/usage. Mismatched days are flagged below.</p>

        <section v-if="chart.length" class="card mt-6 p-5">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">Daily revenue vs cost</h2>
                <div class="flex gap-4 text-xs text-muted"><span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-sm bg-accent"></i>Charged</span><span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-sm bg-muted/50"></i>Provider cost</span></div>
            </div>
            <div class="mt-4 flex h-40 items-end gap-2">
                <div v-for="d in chart" :key="d.day" class="group flex h-full flex-1 flex-col justify-end" :title="`${d.day}: charged ${formatNano(d.charged_nano)} / cost ${formatNano(d.cost_nano)}`">
                    <div class="flex h-full items-end justify-center gap-1">
                        <div class="w-full max-w-[18px] rounded-t bg-accent transition group-hover:brightness-110" :style="`height:${Math.max(d.c, 2)}%`"></div>
                        <div class="w-full max-w-[18px] rounded-t bg-muted/50" :style="`height:${Math.max(d.p, 2)}%`"></div>
                    </div>
                    <p class="mt-2 text-center text-[10px] text-muted">{{ d.day.slice(5) }}</p>
                </div>
            </div>
        </section>

        <div class="mt-6 inline-flex gap-1 rounded-xl border border-line bg-surface p-1 text-sm">
            <button v-for="[k, label] in tabs" :key="k" class="rounded-lg px-4 py-1.5 font-medium transition" :class="tab === k ? 'bg-accent text-accent-fg' : 'text-muted hover:text-fg'" @click="tab = k">{{ label }}</button>
        </div>

        <div class="card mt-4 overflow-x-auto">
            <table class="w-full text-sm tabular-nums">
                <thead class="text-left text-xs text-muted">
                    <tr>
                        <th v-if="tab !== 'user'" class="px-4 py-3 font-medium">Day (UTC)</th>
                        <th v-if="tab !== 'day'" class="px-4 py-3 font-medium">User</th>
                        <th class="px-4 py-3 text-right font-medium">Gens</th>
                        <th class="px-4 py-3 text-right font-medium">Charged</th>
                        <th class="px-4 py-3 text-right font-medium">Provider cost</th>
                        <th class="px-4 py-3 text-right font-medium">Profit</th>
                        <th v-if="tab === 'day'" class="px-4 py-3 text-right font-medium">Pixelforge /usage</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="(r, i) in rows" :key="i" class="transition hover:bg-surface-2">
                        <td v-if="tab !== 'user'" class="px-4 py-2.5">{{ r.day }}</td>
                        <td v-if="tab !== 'day'" class="px-4 py-2.5">{{ r.name }} <span class="text-xs text-muted">{{ r.email }}</span></td>
                        <td class="px-4 py-2.5 text-right">{{ r.generations }}</td>
                        <td class="px-4 py-2.5 text-right">{{ formatNano(r.charged_nano) }}</td>
                        <td class="px-4 py-2.5 text-right">{{ formatNano(r.cost_nano) }}</td>
                        <td class="px-4 py-2.5 text-right font-medium text-emerald-600 dark:text-emerald-400">{{ formatNano(r.profit_nano) }}</td>
                        <td v-if="tab === 'day'" class="px-4 py-2.5 text-right">
                            <template v-if="rec(r.day)">{{ formatNano(rec(r.day).provider_nano) }} <span :class="rec(r.day).match ? 'text-emerald-500' : 'font-bold text-red-500'">{{ rec(r.day).match ? '✓' : '✗' }}</span></template>
                            <span v-else class="text-muted">—</span>
                        </td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="7" class="p-8 text-center text-muted">No billed generations yet.</td></tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
