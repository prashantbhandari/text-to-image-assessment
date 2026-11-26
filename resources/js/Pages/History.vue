<script setup>
// History page: live status, estimated vs actual cost, and the image.
// While anything is queued/processing the page re-fetches itself every 3s.
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatNano } from '@/money';

const props = defineProps({ generations: Array });

const badge = {
    queued: 'bg-slate-100 text-slate-700 ring-slate-300',
    processing: 'bg-amber-50 text-amber-700 ring-amber-200',
    succeeded: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    failed: 'bg-red-50 text-red-700 ring-red-200',
};

const active = computed(() => props.generations.some((g) => ['queued', 'processing'].includes(g.status)));
let timer;
onMounted(() => {
    timer = setInterval(() => {
        if (active.value) router.reload({ only: ['generations'], preserveScroll: true });
    }, 3000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head title="History" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-end justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">History</h1>
                    <p class="mt-1 text-sm text-slate-500">
                        <span v-if="active" class="inline-flex items-center gap-1.5"><span class="h-2 w-2 animate-pulse rounded-full bg-amber-500"></span>Updating live…</span>
                        <span v-else>Your generations, newest first.</span>
                    </p>
                </div>
                <Link :href="route('generations.create')" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">New image</Link>
            </div>
        </template>

        <div v-if="!generations.length" class="rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center text-sm text-slate-500">
            Nothing yet. <Link :href="route('generations.create')" class="font-medium text-indigo-600 underline">Generate your first image</Link>.
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <article v-for="g in generations" :key="g.id" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="relative aspect-square bg-slate-100">
                    <a v-if="g.image_url" :href="g.image_url" target="_blank" rel="noopener">
                        <img :src="g.image_url" :alt="g.prompt" loading="lazy" class="h-full w-full object-cover" />
                    </a>
                    <div v-else class="grid h-full place-items-center p-4 text-center text-sm text-slate-500">
                        <template v-if="g.status === 'failed'">{{ g.error_message || 'Generation failed. You were not charged.' }}</template>
                        <template v-else-if="g.image_expired">Image link expired (Pixelforge keeps images for 24 hours).</template>
                        <template v-else>
                            <div class="flex flex-col items-center gap-2">
                                <span class="h-6 w-6 animate-spin rounded-full border-2 border-slate-300 border-t-indigo-600"></span>
                                {{ g.status === 'queued' ? 'Waiting in queue…' : 'Creating your image…' }}
                            </div>
                        </template>
                    </div>
                </div>
                <div class="space-y-2 p-4">
                    <div class="flex items-center justify-between">
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ring-1" :class="badge[g.status]">{{ g.status }}</span>
                        <span class="text-xs text-slate-500">{{ g.size }} · {{ g.quality }}</span>
                    </div>
                    <p class="line-clamp-2 text-sm" :title="g.prompt">{{ g.prompt }}</p>
                    <dl class="grid grid-cols-2 gap-2 border-t border-slate-100 pt-2 text-xs">
                        <div><dt class="text-slate-500">Estimated</dt><dd class="font-medium">{{ formatNano(g.estimated_cost_nano) }}</dd></div>
                        <div><dt class="text-slate-500">Actual</dt>
                            <dd class="font-medium">{{ g.charged_nano === null ? 'Pending' : formatNano(g.charged_nano, 6) }}</dd></div>
                    </dl>
                </div>
            </article>
        </div>
    </AuthenticatedLayout>
</template>
