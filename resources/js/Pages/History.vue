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

// Long prompts are clamped to 3 lines with a per-card "Show more" toggle.
const LONG_PROMPT = 140;
const expanded = ref(new Set());
const isLong = (g) => g.prompt.length > LONG_PROMPT || g.prompt.split('\n').length > 3;
const toggle = (id) => {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
};

// Actual vs estimate, e.g. "$0.0012 under estimate".
const delta = (g) => {
    if (g.charged_nano === null || g.status !== 'succeeded') return null;
    const d = g.charged_nano - g.estimated_cost_nano;
    return { text: `${formatNano(Math.abs(d), 4)} ${d <= 0 ? 'under' : 'over'} estimate`, good: d <= 0 };
};

const lightbox = ref(null);
const copied = ref(null);
const pendingDelete = ref(null);
const deleting = ref(false);
const canDelete = (g) => !isActive(g);
const confirmDelete = () => {
    deleting.value = true;
    router.delete(route('generations.destroy', pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => { deleting.value = false; pendingDelete.value = null; },
    });
};
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
const onKey = (e) => e.key === 'Escape' && ((lightbox.value = null), (pendingDelete.value = null));
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

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <article v-for="g in shown" :key="g.id" class="card fade-up group flex flex-col overflow-hidden">
                <div class="relative aspect-[4/3] shrink-0 overflow-hidden bg-surface-2">
                    <template v-if="g.image_url">
                        <img :src="g.image_url" alt="" aria-hidden="true" class="absolute inset-0 h-full w-full scale-125 object-cover opacity-60 blur-2xl" />
                        <img :src="g.image_url" :alt="g.prompt" loading="lazy" class="relative h-full w-full cursor-zoom-in object-contain" @click="lightbox = g" />
                        <div class="pointer-events-none absolute inset-x-0 bottom-0 flex justify-end gap-2 bg-gradient-to-t from-black/60 to-transparent p-3 opacity-0 transition group-hover:opacity-100">
                            <button class="pointer-events-auto rounded-lg bg-white/90 px-3 py-1.5 text-xs font-semibold text-black hover:bg-white" @click="lightbox = g">View</button>
                            <a :href="g.image_url" target="_blank" rel="noopener" download class="pointer-events-auto rounded-lg bg-white/90 px-3 py-1.5 text-xs font-semibold text-black hover:bg-white">Open original</a>
                        </div>
                    </template>
                    <div v-else-if="g.status === 'failed'" class="grid h-full place-items-center p-6 text-center">
                        <div><div class="mx-auto grid h-10 w-10 place-items-center rounded-full bg-red-500/10 text-red-500">!</div>
                            <p class="mt-3 text-sm font-medium">Couldn't create this one</p>
                            <p class="mt-1 line-clamp-4 break-words text-xs text-muted" :title="g.error_message">{{ g.error_message || 'No charge was made.' }}</p></div>
                    </div>
                    <div v-else-if="g.image_expired" class="grid h-full place-items-center p-6 text-center text-sm text-muted">Image link expired (kept for 24 hours).</div>
                    <div v-else class="shimmer grid h-full place-items-center">
                        <div class="text-center"><span class="mx-auto block h-7 w-7 animate-spin rounded-full border-2 border-line border-t-accent"></span>
                            <p class="mt-3 text-sm font-medium">{{ g.status === 'queued' ? 'Waiting in line…' : 'Developing your image…' }}</p></div>
                    </div>
                </div>

                <div class="flex flex-1 flex-col gap-3 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <StatusBadge :status="g.status" />
                        <span class="text-xs text-muted">{{ g.size.replace('x', ' × ') }} · {{ g.quality }}</span>
                    </div>
                    <div>
                        <p class="whitespace-pre-line break-words text-sm leading-relaxed [overflow-wrap:anywhere]" :class="expanded.has(g.id) ? 'max-h-48 overflow-y-auto pr-1' : 'line-clamp-3'">{{ g.prompt }}</p>
                        <button v-if="isLong(g)" class="mt-1 text-xs font-medium text-brand hover:underline" @click="toggle(g.id)">{{ expanded.has(g.id) ? 'Show less' : 'Show more' }}</button>
                    </div>
                    <div class="mt-auto flex items-end justify-between border-t border-line pt-3 text-xs">
                        <div>
                            <p class="text-muted">Estimated</p><p class="font-medium tabular-nums">{{ formatNano(g.estimated_cost_nano) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-muted">{{ g.status === 'failed' ? 'Charged' : 'Actual' }}</p>
                            <p class="font-semibold tabular-nums">{{ g.charged_nano === null ? 'Pending…' : formatNano(g.charged_nano, 6) }}</p>
                            <p v-if="delta(g)" class="tabular-nums" :class="delta(g).good ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">{{ delta(g).text }}</p>
                        </div>
                    </div>
                    <div class="-mx-1 flex items-center gap-1 border-t border-line pt-3">
                        <a v-if="g.image_url" :href="route('generations.download', g.id)" class="icon-btn" title="Download image" aria-label="Download image">
                            <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-4.5-4.5M12 15l4.5-4.5" /><path d="M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2" /></svg>
                        </a>
                        <button class="icon-btn" :title="copied === g.id ? 'Copied!' : 'Copy prompt'" aria-label="Copy prompt" @click="copyPrompt(g)">
                            <svg v-if="copied !== g.id" viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2" /><path d="M5 15V5a2 2 0 012-2h10" /></svg>
                            <svg v-else viewBox="0 0 24 24" class="h-[18px] w-[18px] text-emerald-500" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                        </button>
                        <Link :href="route('generations.create', { reuse: g.id })" class="icon-btn" title="Reuse prompt and settings" aria-label="Reuse prompt and settings">
                            <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0115.5-6.2L21 8" /><path d="M21 3v5h-5" /><path d="M21 12a9 9 0 01-15.5 6.2L3 16" /><path d="M3 21v-5h5" /></svg>
                        </Link>
                        <span class="flex-1"></span>
                        <button v-if="canDelete(g)" class="icon-btn hover:!bg-red-500/10 hover:!text-red-500" title="Delete" aria-label="Delete" @click="pendingDelete = g">
                            <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 011-1h6a1 1 0 011 1v2" /><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6" /><path d="M10 11v6M14 11v6" /></svg>
                        </button>
                    </div>
                </div>
            </article>
        </div>

        <!-- Delete confirmation -->
        <Teleport to="body">
            <div v-if="pendingDelete" class="fixed inset-0 z-[60] grid place-items-center bg-black/70 p-4 backdrop-blur-sm" role="alertdialog" aria-modal="true" aria-labelledby="del-title" @click.self="pendingDelete = null">
                <div class="card fade-up w-full max-w-sm p-6">
                    <h2 id="del-title" class="text-lg font-semibold">Delete this image?</h2>
                    <p class="mt-2 line-clamp-3 break-words text-sm text-muted [overflow-wrap:anywhere]">{{ pendingDelete.prompt }}</p>
                    <p class="mt-3 text-sm text-muted">It will be removed from your history. Past charges are not refunded or changed.</p>
                    <div class="mt-5 flex justify-end gap-2">
                        <button class="btn-ghost" :disabled="deleting" @click="pendingDelete = null">Cancel</button>
                        <button class="inline-flex items-center justify-center rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-500 disabled:opacity-50" :disabled="deleting" @click="confirmDelete">{{ deleting ? 'Deleting…' : 'Delete' }}</button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Lightbox -->
        <Teleport to="body">
            <div v-if="lightbox" class="fixed inset-0 z-[60] grid place-items-center bg-black/85 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" @click.self="lightbox = null">
                <button class="absolute right-4 top-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20" aria-label="Close" @click="lightbox = null">✕</button>
                <figure class="flex max-h-full min-w-0 max-w-4xl flex-col items-center">
                    <img :src="lightbox.image_url" :alt="lightbox.prompt" class="mx-auto block max-h-[80vh] max-w-full rounded-xl object-contain shadow-2xl" />
                    <figcaption class="mx-auto mt-3 max-h-24 max-w-2xl overflow-y-auto whitespace-pre-line break-words text-center text-sm text-white/80 [overflow-wrap:anywhere]">{{ lightbox.prompt }}</figcaption>
                </figure>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
