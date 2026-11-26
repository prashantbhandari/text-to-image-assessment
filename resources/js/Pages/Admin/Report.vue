<script setup>
// Operator report: credit spend vs provider cost vs profit by day / user / day x user,
// for a chosen UTC date range. Provider cost is reconciled with Pixelforge's GET /v1/usage.
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatNano as fmt } from '@/money';

// Exact 9-decimal output so figures can be compared one-to-one with /v1/usage.
const formatNano = (n) => fmt(n, 9);

const props = defineProps({
    range: Object, per_day: Array, per_user: Array, per_day_user: Array,
    totals: Object, series: Array, reconciliation: Object,
});

const from = ref(props.range.from);
const to = ref(props.range.to);
const apply = () => router.get(route('admin.report'), { from: from.value, to: to.value }, { preserveScroll: true, preserveState: true });
const preset = (days) => {
    const end = new Date();
    const start = new Date(Date.now() - (days - 1) * 86400000);
    from.value = start.toISOString().slice(0, 10);
    to.value = end.toISOString().slice(0, 10);
    apply();
};

const tab = ref('day');
const tabs = [['day', 'By day'], ['user', 'By user'], ['day_user', 'Day × user']];
const rows = computed(() => ({ day: props.per_day, user: props.per_user, day_user: props.per_day_user })[tab.value]);
const exportUrl = computed(() => route('admin.report.export', { view: tab.value, from: props.range.from, to: props.range.to }));
const margin = computed(() => (props.totals.charged_nano ? ((props.totals.profit_nano / props.totals.charged_nano) * 100).toFixed(1) : '0.0'));
const recByDay = computed(() => Object.fromEntries((props.reconciliation?.rows ?? []).map((r) => [r.day, r])));
const showRec = ref(false);

// Bars scale to the busiest day's revenue.
const maxBar = computed(() => Math.max(1, ...props.series.map((d) => d.charged_nano)));
const bar = (n) => Math.max((n / maxBar.value) * 100, n > 0 ? 3 : 0);

const stats = computed(() => [
    ['Credit spend', formatNano(props.totals.charged_nano), 'Charged to users'],
    ['Provider cost', formatNano(props.totals.cost_nano), 'Billed by Pixelforge'],
    ['Profit', formatNano(props.totals.profit_nano), `${margin.value}% margin`],
    ['Generations', String(props.totals.generations), 'Billed (succeeded)'],
]);
</script>

<template>
    <Head title="Admin · Report" />
    <AdminLayout title="Report" subtitle="Succeeded generations only, bucketed by the UTC day they completed. The same rule Pixelforge's usage report uses.">
        <!-- Date range -->
        <form class="card flex flex-wrap items-end gap-3 p-4" @submit.prevent="apply">
            <label class="text-xs font-medium text-muted">From<input v-model="from" type="date" class="mt-1 block rounded-xl border-line text-sm focus:border-accent focus:ring-accent" /></label>
            <label class="text-xs font-medium text-muted">To<input v-model="to" type="date" class="mt-1 block rounded-xl border-line text-sm focus:border-accent focus:ring-accent" /></label>
            <button class="btn-accent !py-2">Apply</button>
            <div class="ml-auto flex flex-wrap gap-2">
                <button type="button" class="btn-ghost !px-3 !py-1.5" @click="preset(7)">7 days</button>
                <button type="button" class="btn-ghost !px-3 !py-1.5" @click="preset(30)">30 days</button>
                <button type="button" class="btn-ghost !px-3 !py-1.5" @click="preset(90)">90 days</button>
            </div>
            <p class="w-full text-xs text-muted">Showing {{ range.from }} → {{ range.to }} (UTC). Maximum {{ range.max_days }} days, the same limit as Pixelforge's usage API.</p>
        </form>

        <!-- Totals -->
        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="([label, value, sub], i) in stats" :key="label" class="card p-5" :class="i === 2 ? 'ring-1 ring-accent/40' : ''">
                <p class="text-xs font-semibold uppercase tracking-wider text-muted">{{ label }}</p>
                <p class="font-display mt-1 truncate text-2xl font-semibold tabular-nums" :class="i === 2 ? 'text-emerald-600 dark:text-emerald-400' : ''" :title="value">{{ value }}</p>
                <p class="mt-0.5 text-xs text-muted">{{ sub }}</p>
            </div>
        </div>

        <!-- Reconciliation banner -->
        <div class="mt-5">
            <p v-if="reconciliation === null" class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-300">Could not reach Pixelforge /v1/usage to reconcile. Check the API key.</p>
            <div v-else class="rounded-xl border p-3 text-sm" :class="reconciliation.matches ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-800 dark:text-emerald-300' : 'border-red-500/30 bg-red-500/10 text-red-800 dark:text-red-300'">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p v-if="reconciliation.matches">✓ Matches Pixelforge /v1/usage on every day (their total {{ formatNano(reconciliation.theirs_total_nano) }}).</p>
                    <p v-else>⚠ {{ reconciliation.mismatches }} day(s) differ from Pixelforge /v1/usage. Their total: {{ formatNano(reconciliation.theirs_total_nano) }}, ours: {{ formatNano(totals.cost_nano) }}.</p>
                    <button class="font-semibold underline" @click="showRec = !showRec">{{ showRec ? 'Hide' : 'Show' }} day-by-day check</button>
                </div>
                <div v-if="showRec" class="mt-3 overflow-x-auto rounded-lg bg-surface text-fg">
                    <table class="w-full text-xs tabular-nums">
                        <thead class="text-left text-muted"><tr><th class="px-3 py-2 font-medium">Day</th><th class="px-3 py-2 text-right font-medium">Our cost</th><th class="px-3 py-2 text-right font-medium">Pixelforge</th><th class="px-3 py-2 text-right font-medium">Difference</th><th class="px-3 py-2 text-right font-medium">Gens (ours / theirs)</th><th class="px-3 py-2"></th></tr></thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="r in reconciliation.rows" :key="r.day" :class="r.match ? '' : 'bg-red-500/10'">
                                <td class="px-3 py-1.5">{{ r.day }}</td>
                                <td class="px-3 py-1.5 text-right">{{ formatNano(r.ours_nano) }}</td>
                                <td class="px-3 py-1.5 text-right">{{ formatNano(r.theirs_nano) }}</td>
                                <td class="px-3 py-1.5 text-right" :class="r.diff_nano ? 'font-bold text-red-500' : 'text-muted'">{{ r.diff_nano ? formatNano(r.diff_nano) : '—' }}</td>
                                <td class="px-3 py-1.5 text-right">{{ r.ours_generations }} / {{ r.theirs_generations }}</td>
                                <td class="px-3 py-1.5 text-right">{{ r.match ? '✓' : '✗' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Chart -->
        <section class="card mt-5 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Revenue vs cost per day</h2>
                <div class="flex gap-4 text-xs text-muted"><span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-sm bg-accent"></i>Charged</span><span class="flex items-center gap-1.5"><i class="h-2.5 w-2.5 rounded-sm bg-muted/50"></i>Provider cost</span></div>
            </div>
            <div class="mt-4 flex h-44 items-end gap-1 overflow-x-auto">
                <div v-for="d in series" :key="d.day" class="group flex h-full min-w-[14px] flex-1 flex-col justify-end"
                    :title="`${d.day}\nCharged ${formatNano(d.charged_nano)}\nCost ${formatNano(d.cost_nano)}\nProfit ${formatNano(d.profit_nano)}`">
                    <div class="flex h-full items-end justify-center gap-px">
                        <div class="w-full max-w-[16px] rounded-t bg-accent transition group-hover:brightness-110" :style="`height:${bar(d.charged_nano)}%`"></div>
                        <div class="w-full max-w-[16px] rounded-t bg-muted/50" :style="`height:${bar(d.cost_nano)}%`"></div>
                    </div>
                </div>
            </div>
            <div class="mt-2 flex justify-between text-[11px] text-muted"><span>{{ range.from }}</span><span>{{ range.to }}</span></div>
        </section>

        <!-- Tables -->
        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex gap-1 rounded-xl border border-line bg-surface p-1 text-sm">
                <button v-for="[k, label] in tabs" :key="k" class="rounded-lg px-4 py-1.5 font-medium transition" :class="tab === k ? 'bg-accent text-accent-fg' : 'text-muted hover:text-fg'" @click="tab = k">{{ label }}</button>
            </div>
            <a :href="exportUrl" class="btn-ghost !py-2">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-4.5-4.5M12 15l4.5-4.5M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2" /></svg> Export CSV
            </a>
        </div>

        <div class="card mt-3 overflow-x-auto">
            <table class="w-full text-sm tabular-nums">
                <thead class="text-left text-xs text-muted">
                    <tr>
                        <th v-if="tab !== 'user'" class="px-4 py-3 font-medium">Day (UTC)</th>
                        <th v-if="tab !== 'day'" class="px-4 py-3 font-medium">User</th>
                        <th class="px-4 py-3 text-right font-medium">Gens</th>
                        <th class="px-4 py-3 text-right font-medium">Charged</th>
                        <th class="px-4 py-3 text-right font-medium">Provider cost</th>
                        <th class="px-4 py-3 text-right font-medium">Profit</th>
                        <th v-if="tab === 'day' && reconciliation" class="px-4 py-3 text-right font-medium">vs Pixelforge</th>
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
                        <td v-if="tab === 'day' && reconciliation" class="px-4 py-2.5 text-right">
                            <span v-if="recByDay[r.day]" :class="recByDay[r.day].match ? 'text-emerald-500' : 'font-bold text-red-500'">{{ recByDay[r.day].match ? '✓ match' : '✗ differs' }}</span>
                            <span v-else class="text-muted">—</span>
                        </td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="7" class="p-10 text-center text-muted">No billed generations in this range.</td></tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
