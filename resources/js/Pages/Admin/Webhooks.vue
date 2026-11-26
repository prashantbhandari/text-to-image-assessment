<script setup>
// Webhook log: every accepted Pixelforge event, how many times it was delivered, and
// whether it changed anything. Makes duplicate / out-of-order delivery visible.
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({ events: Object, filters: Object, types: Array, stats: Object });

const q = ref(props.filters.q ?? '');
const type = ref(props.filters.type ?? '');
const only = ref(props.filters.only ?? '');
const go = () => router.get(route('admin.webhooks'), { q: q.value || undefined, type: type.value || undefined, only: only.value || undefined }, { preserveState: true, replace: true });
let t;
watch(q, () => { clearTimeout(t); t = setTimeout(go, 300); });
watch([type, only], go);

const outcome = {
    applied: ['bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 ring-emerald-500/30', 'Applied'],
    ignored: ['bg-surface-2 text-muted ring-line', 'Ignored (stale / out of order)'],
    unmatched: ['bg-red-500/10 text-red-700 dark:text-red-300 ring-red-500/30', 'No matching generation'],
};
const when = (iso) => new Date(iso).toLocaleString();

const cards = [['total', 'Events received'], ['duplicates', 'Re-delivered (duplicates)'], ['ignored', 'Ignored as stale'], ['unmatched', 'Unmatched']];
</script>

<template>
    <Head title="Admin · Webhooks" />
    <AdminLayout title="Webhooks" subtitle="Each event is stored once. Re-deliveries only bump the counter, so a duplicate can never be recorded twice.">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="[k, label] in cards" :key="k" class="card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-muted">{{ label }}</p><p class="font-display mt-1 text-3xl font-semibold tabular-nums">{{ stats[k] }}</p></div>
        </div>

        <div class="mt-5 flex flex-wrap gap-3">
            <input v-model="q" type="search" placeholder="Search event or generation ID…" aria-label="Search events" class="w-full rounded-xl border-line text-sm focus:border-accent focus:ring-accent sm:w-72" />
            <select v-model="type" aria-label="Event type" class="rounded-xl border-line text-sm focus:border-accent focus:ring-accent">
                <option value="">All types</option>
                <option v-for="tp in types" :key="tp" :value="tp">{{ tp }}</option>
            </select>
            <select v-model="only" aria-label="Filter" class="rounded-xl border-line text-sm focus:border-accent focus:ring-accent">
                <option value="">All events</option>
                <option value="duplicates">Re-delivered only</option>
                <option value="ignored">Ignored only</option>
            </select>
        </div>

        <div class="card mt-3 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted"><tr><th class="px-4 py-3 font-medium">Received</th><th class="px-4 py-3 font-medium">Event</th><th class="px-4 py-3 font-medium">Generation</th><th class="px-4 py-3 text-right font-medium">Deliveries</th><th class="px-4 py-3 font-medium">Outcome</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="e in events.data" :key="e.id" class="align-top transition hover:bg-surface-2">
                            <td class="whitespace-nowrap px-4 py-3 text-muted">{{ when(e.received_at) }}</td>
                            <td class="px-4 py-3"><p class="font-medium">{{ e.type }}</p><p class="break-all font-mono text-[11px] text-muted">{{ e.event_id }}</p></td>
                            <td class="px-4 py-3">
                                <p class="break-all font-mono text-[11px]">{{ e.provider_id }}</p>
                                <p v-if="e.user" class="text-xs text-muted">{{ e.user }}</p>
                                <p v-if="e.prompt" class="max-w-xs break-words text-xs text-muted [overflow-wrap:anywhere]">{{ e.prompt }}</p>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <span :class="e.delivery_count > 1 ? 'rounded-full bg-amber-500/10 px-2 py-0.5 font-semibold text-amber-700 ring-1 ring-amber-500/30 dark:text-amber-300' : ''">×{{ e.delivery_count }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="e.outcome" class="inline-block rounded-full px-2.5 py-1 text-xs font-medium ring-1" :class="outcome[e.outcome][0]">{{ outcome[e.outcome][1] }}</span>
                                <span v-else class="text-muted">—</span>
                            </td>
                        </tr>
                        <tr v-if="!events.data.length"><td colspan="5" class="p-10 text-center text-muted">No webhook events yet.</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :page="events" />
        </div>
    </AdminLayout>
</template>
