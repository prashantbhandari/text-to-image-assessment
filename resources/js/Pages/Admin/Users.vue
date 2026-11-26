<script setup>
// Admin users list: balances, lifetime spend, activity, and manual credit adjustments.
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatNano } from '@/money';

const props = defineProps({ users: Object, filters: Object, totals: Object });

// Debounced search (server-side, keeps pagination correct).
const q = ref(props.filters.q);
let t;
watch(q, (v) => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('admin.users'), { q: v || undefined }, { preserveState: true, replace: true }), 300);
});

// Credit adjustment dialog
const target = ref(null);
const form = useForm({ amount: '', note: '' });
const open = (u) => { target.value = u; form.reset(); form.clearErrors(); };
const close = () => (target.value = null);
const submit = () =>
    form.post(route('admin.users.credits', target.value.id), { preserveScroll: true, onSuccess: close });
</script>

<template>
    <Head title="Admin · Users" />
    <AdminLayout title="Users" subtitle="Balances, lifetime spend and activity. Adjustments are recorded in the credit ledger.">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-muted">Users</p><p class="font-display mt-1 text-3xl font-semibold tabular-nums">{{ totals.users }}</p></div>
            <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wider text-muted">Credits held by users</p><p class="font-display mt-1 text-3xl font-semibold tabular-nums">{{ formatNano(totals.balance_nano) }}</p></div>
        </div>

        <div class="mt-5">
            <input v-model="q" type="search" placeholder="Search name or email…" aria-label="Search users" class="w-full rounded-xl border-line text-sm focus:border-accent focus:ring-accent sm:max-w-sm" />
        </div>

        <div class="card mt-3 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted">
                        <tr><th class="px-4 py-3 font-medium">User</th><th class="px-4 py-3 text-right font-medium">Balance</th><th class="px-4 py-3 text-right font-medium">Lifetime spend</th><th class="px-4 py-3 text-right font-medium">Images</th><th class="px-4 py-3 text-right font-medium">Failed</th><th class="px-4 py-3 font-medium">Joined</th><th class="px-4 py-3"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="u in users.data" :key="u.id" class="transition hover:bg-surface-2">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ u.name }} <span v-if="u.is_admin" class="ml-1 rounded bg-accent/15 px-1.5 py-0.5 text-[10px] font-bold uppercase text-brand">Admin</span></p>
                                <p class="text-xs text-muted">{{ u.email }}</p>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatNano(u.balance_nano) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatNano(u.spent_nano, 6) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ u.images }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-muted">{{ u.failed }}</td>
                            <td class="px-4 py-3 text-muted">{{ u.joined }}</td>
                            <td class="px-4 py-3 text-right"><button class="btn-ghost !px-3 !py-1.5" @click="open(u)">Adjust credits</button></td>
                        </tr>
                        <tr v-if="!users.data.length"><td colspan="7" class="p-10 text-center text-muted">No users match.</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :page="users" />
        </div>

        <!-- Adjust credits dialog -->
        <Teleport to="body">
            <div v-if="target" class="fixed inset-0 z-[60] grid place-items-center bg-black/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="adj-title" @click.self="close" @keydown.esc="close">
                <form class="card fade-up w-full max-w-md p-6" @submit.prevent="submit">
                    <h2 id="adj-title" class="text-lg font-semibold">Adjust credits</h2>
                    <p class="mt-1 text-sm text-muted">{{ target.name }} · {{ target.email }}<br />Current balance <strong class="text-fg">{{ formatNano(target.balance_nano) }}</strong></p>

                    <label class="mt-5 block text-sm font-medium" for="adj-amount">Amount (USD)</label>
                    <input id="adj-amount" v-model="form.amount" type="text" inputmode="decimal" placeholder="5 to add, -2.50 to remove" autofocus class="mt-1 w-full rounded-xl border-line text-sm focus:border-accent focus:ring-accent" />
                    <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.amount }}</p>

                    <label class="mt-4 block text-sm font-medium" for="adj-note">Note <span class="font-normal text-muted">(shown to the user)</span></label>
                    <input id="adj-note" v-model="form.note" type="text" maxlength="200" placeholder="e.g. Goodwill credit" class="mt-1 w-full rounded-xl border-line text-sm focus:border-accent focus:ring-accent" />

                    <div class="mt-6 flex justify-end gap-2">
                        <button type="button" class="btn-ghost" @click="close">Cancel</button>
                        <button class="btn-accent" :disabled="form.processing || !form.amount">{{ form.processing ? 'Saving…' : 'Apply adjustment' }}</button>
                    </div>
                </form>
            </div>
        </Teleport>
    </AdminLayout>
</template>
