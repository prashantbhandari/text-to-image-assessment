<script setup>
// History: filterable gallery with live status, estimate vs actual cost, and a lightbox.
// While anything is queued/processing the page re-fetches itself every 3s.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatNano } from '@/money';

const props = defineProps({ generations: Array });

const filter = ref('all');
const filters = [['all', 'All'], ['active', 'In progress'], ['succeeded', 'Done'], ['failed', 'Failed']];
const isActive = (g) => ['queued', 'processing'].includes(g.status);
const count = (k) => props.generations.filter((g) => (k === 'all' ? true : k === 'active' ? isActive(g) : g.status === k)).length;
const shown = computed(() => props.generations.filter((g) => (filter.value === 'all' ? true : filter.value === 'active' ? isActive(g) : g.status === filter.value)));
const active = computed(() => props.generations.some(isActive));

// Card image area follows the generation's real aspect ratio.
const ratio = (size) => ({ '1536x1024': 'aspect-[3/2]', '1024x1536': 'aspect-[2/3]' })[size] ?? 'aspect-square';

// Actual vs estimate, e.g. "$0.0012 under estimate".
const delta = (g) => {
    if (g.charged_nano === null || g.status !== 'succeeded') return null;
    const d = g.charged_nano - g.estimated_cost_nano;
    return { text: `${formatNano(Math.abs(d), 4)} ${d <= 0 ? 'under' : 'over'} estimate`, good: d <= 0 };
};

const lightbox = ref(null);
const copied = ref(null);
const copyPrompt = async (g) => {
    await navigator.clipboard?.writeText(g.prompt);
    copied.value = g.id;
    setTimeout(() => (copied.value = null), 1500);
};

let timer;
onMounted(() => {
    timer = setInterval(() => active.value && router.reload({ only: ['generations'], preserveScroll: true }), 3000);
    window.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => { clearInterval(timer); window.removeEventListener('keydown', onKey); });
const onKey = (e) => e.key === 'Escape' && (lightbox.value = null);
</script>

<template>
    <Head title="History" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-4xl font-semibold sm:text-5xl">Your <span class="italic text-brand">gallery</span></h1>
                    <p class="mt-2 text-muted">
                        <span v-if="active" class="inline-flex items-center gap-2"><span class="h-2 w-2 animate-pulse rounded-full bg-amber-500"></span>Developing… this page updates by itself.</span>
                        <span v-else>Every image you've made, newest first.</span>
                    </p>
                </div>
                <Link :href="route('generations.create')" class="btn-accent">+ New image</Link>
            </div>
        </template>

        <div class="mb-6 flex flex-wrap gap-2">
            <button v-for="[k, label] in filters" :key="k" class="rounded-full border px-4 py-1.5 text-sm font-medium transition"
                :class="filter === k ? 'border-accent bg-accent text-accent-fg' : 'border-line bg-surface text-muted hover:text-fg'" @click="filter = k">
                {{ label }} <span class="ml-1 tabular-nums opacity-70">{{ count(k) }}</span>
            </button>
        </div>

        <div v-if="!shown.length" class="card grid place-items-center px-6 py-20 text-center">
            <div class="grid h-14 w-14 place-items-center rounded-2xl bg-accent/10 text-brand"><svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3" /><circle cx="9" cy="9" r="2" /><path d="m21 15-5-5L5 21" /></svg></div>
            <h2 class="mt-4 text-lg font-semibold">{{ generations.length ? 'Nothing in this view' : 'No images yet' }}</h2>
            <p class="mt-1 max-w-sm text-sm text-muted">{{ generations.length ? 'Try a different filter.' : 'Write your first prompt and it will appear here as it develops.' }}</p>
            <Link v-if="!generations.length" :href="route('generations.create')" class="btn-accent mt-5">Create your first image</Link>
        </div>

        <div class="columns-1 gap-5 sm:columns-2 lg:columns-3">
            <article v-for="g in shown" :key="g.id" class="card fade-up group mb-5 break-inside-avoid overflow-hidden">
                <div class="relative bg-surface-2" :class="ratio(g.size)">
                    <template v-if="g.image_url">
                        <img :src="g.image_url" :alt="g.prompt" loading="lazy" class="h-full w-full cursor-zoom-in object-cover" @click="lightbox = g" />
                        <div class="pointer-events-none absolute inset-x-0 bottom-0 flex justify-end gap-2 bg-gradient-to-t from-black/60 to-transparent p-3 opacity-0 transition group-hover:opacity-100">
                            <button class="pointer-events-auto rounded-lg bg-white/90 px-3 py-1.5 text-xs font-semibold text-black hover:bg-white" @click="lightbox = g">View</button>
                            <a :href="g.image_url" target="_blank" rel="noopener" download class="pointer-events-auto rounded-lg bg-white/90 px-3 py-1.5 text-xs font-semibold text-black hover:bg-white">Open original</a>
                        </div>
                    </template>
                    <div v-else-if="g.status === 'failed'" class="grid h-full place-items-center p-6 text-center">
                        <div><div class="mx-auto grid h-10 w-10 place-items-center rounded-full bg-red-500/10 text-red-500">!</div>
                            <p class="mt-3 text-sm font-medium">Couldn't create this one</p>
                            <p class="mt-1 text-xs text-muted">{{ g.error_message || 'No charge was made.' }}</p></div>
                    </div>
                    <div v-else-if="g.image_expired" class="grid h-full place-items-center p-6 text-center text-sm text-muted">Image link expired (kept for 24 hours).</div>
                    <div v-else class="shimmer grid h-full place-items-center">
                        <div class="text-center"><span class="mx-auto block h-7 w-7 animate-spin rounded-full border-2 border-line border-t-accent"></span>
                            <p class="mt-3 text-sm font-medium">{{ g.status === 'queued' ? 'Waiting in line…' : 'Developing your image…' }}</p></div>
                    </div>
                </div>

                <div class="space-y-3 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <StatusBadge :status="g.status" />
                        <span class="text-xs text-muted">{{ g.size.replace('x', ' × ') }} · {{ g.quality }}</span>
                    </div>
                    <p class="line-clamp-3 text-sm leading-relaxed" :title="g.prompt">{{ g.prompt }}</p>
                    <div class="flex items-end justify-between border-t border-line pt-3 text-xs">
                        <div>
                            <p class="text-muted">Estimated</p><p class="font-medium tabular-nums">{{ formatNano(g.estimated_cost_nano) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-muted">{{ g.status === 'failed' ? 'Charged' : 'Actual' }}</p>
                            <p class="font-semibold tabular-nums">{{ g.charged_nano === null ? 'Pending…' : formatNano(g.charged_nano, 6) }}</p>
                            <p v-if="delta(g)" class="tabular-nums" :class="delta(g).good ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">{{ delta(g).text }}</p>
                        </div>
                    </div>
                    <button class="text-xs text-muted transition hover:text-fg" @click="copyPrompt(g)">{{ copied === g.id ? '✓ Copied' : 'Copy prompt' }}</button>
                </div>
            </article>
        </div>

        <!-- Lightbox -->
        <Teleport to="body">
            <div v-if="lightbox" class="fixed inset-0 z-[60] grid place-items-center bg-black/85 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" @click.self="lightbox = null">
                <button class="absolute right-4 top-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20" aria-label="Close" @click="lightbox = null">✕</button>
                <figure class="max-h-full max-w-4xl">
                    <img :src="lightbox.image_url" :alt="lightbox.prompt" class="max-h-[80vh] rounded-xl object-contain shadow-2xl" />
                    <figcaption class="mt-3 text-center text-sm text-white/80">{{ lightbox.prompt }}</figcaption>
                </figure>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
