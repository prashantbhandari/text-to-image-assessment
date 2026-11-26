<script setup>
// Create page: prompt, size, quality, reference URLs and a live, explained price estimate.
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { estimatePrice, formatNano } from '@/money';

const props = defineProps({
    pricing: Object,
    available_nano: Number, // balance minus the worst-case reserve of in-flight jobs
});

const form = useForm({ prompt: '', size: '1024x1024', quality: 'medium', reference_images: [] });

// Size tiles draw the actual aspect ratio so the choice is visual.
const sizes = [
    { value: '1024x1024', label: 'Square', hint: '1:1', box: 'h-9 w-9' },
    { value: '1536x1024', label: 'Landscape', hint: '3:2', box: 'h-8 w-12' },
    { value: '1024x1536', label: 'Portrait', hint: '2:3', box: 'h-12 w-8' },
];
const qualities = [
    { value: 'low', label: 'Draft', hint: 'Quick sketch' },
    { value: 'medium', label: 'Standard', hint: 'Balanced' },
    { value: 'high', label: 'Fine detail', hint: 'Best quality' },
];
const ideas = [
    'A lighthouse on a cliff at dusk, oil painting',
    'Isometric tiny cafe interior, warm lamps, 3D render',
    'Macro photo of a dewdrop on a fern, soft morning light',
    'Retro travel poster of a mountain railway, bold flat colors',
];

const filledRefs = computed(() => form.reference_images.filter((u) => u.trim() !== ''));
const priceFor = (quality, size = form.size) =>
    estimatePrice(props.pricing, { prompt: form.prompt, size, quality, referenceCount: filledRefs.value.length });

const estimate = computed(() => priceFor(form.quality));
const canAfford = computed(() => props.available_nano >= estimate.value.max);
// Position of the expected price inside the likely range (for the range bar).
const marker = computed(() => {
    const { min, max, expected } = estimate.value;
    return max === min ? 50 : Math.round(((expected - min) / (max - min)) * 100);
});
const ready = computed(() => form.prompt.trim() !== '' && canAfford.value && !form.processing);

const addRef = () => form.reference_images.length < props.pricing.max_reference_images && form.reference_images.push('');
const removeRef = (i) => form.reference_images.splice(i, 1);

const submit = () => {
    if (!ready.value) return;
    form.transform((d) => ({ ...d, reference_images: d.reference_images.filter((u) => u.trim() !== '') })).post(route('generations.store'));
};
</script>

<template>
    <Head title="Create" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-4xl font-semibold sm:text-5xl">What shall we <span class="italic text-brand">create?</span></h1>
            <p class="mt-2 max-w-xl text-muted">Describe the image. You'll see the price before you commit, and you only pay when it succeeds.</p>
        </template>

        <form class="grid gap-6 lg:grid-cols-[1fr_340px]" @submit.prevent="submit">
            <div class="space-y-6">
                <!-- Prompt -->
                <section class="card p-5">
                    <label for="prompt" class="text-sm font-semibold">Prompt</label>
                    <textarea id="prompt" v-model="form.prompt" rows="5" maxlength="4000" placeholder="Describe the subject, style, lighting, mood…"
                        class="mt-2 w-full resize-none rounded-xl border-line text-[15px] leading-relaxed focus:border-accent focus:ring-accent"
                        @keydown.ctrl.enter="submit" @keydown.meta.enter="submit" />
                    <div class="mt-1 flex items-center justify-between text-xs text-muted">
                        <span class="text-red-600 dark:text-red-400">{{ form.errors.prompt }}</span>
                        <span class="tabular-nums">{{ [...form.prompt].length }} / 4000 · Ctrl+Enter to generate</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="py-1 text-xs text-muted">Try:</span>
                        <button v-for="i in ideas" :key="i" type="button" class="rounded-full border border-line bg-surface-2 px-3 py-1 text-xs text-muted transition hover:border-accent hover:text-fg" @click="form.prompt = i">{{ i }}</button>
                    </div>
                </section>

                <!-- Size -->
                <section class="card p-5">
                    <h2 class="text-sm font-semibold">Shape</h2>
                    <div class="mt-3 grid grid-cols-3 gap-3">
                        <label v-for="s in sizes" :key="s.value" class="group relative cursor-pointer rounded-xl border p-4 text-center transition"
                            :class="form.size === s.value ? 'border-accent bg-accent/10 ring-1 ring-accent' : 'border-line hover:border-muted'">
                            <input v-model="form.size" type="radio" :value="s.value" class="sr-only" />
                            <div class="mx-auto grid h-12 place-items-center">
                                <span :class="[s.box, form.size === s.value ? 'border-accent bg-accent/20' : 'border-muted/50 bg-surface-2']" class="rounded-md border-2 transition"></span>
                            </div>
                            <p class="mt-2 text-sm font-medium">{{ s.label }}</p>
                            <p class="text-xs text-muted">{{ s.hint }} · {{ s.value }}</p>
                        </label>
                    </div>
                </section>

                <!-- Quality, each with its own live price -->
                <section class="card p-5">
                    <h2 class="text-sm font-semibold">Quality</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <label v-for="q in qualities" :key="q.value" class="cursor-pointer rounded-xl border p-4 transition"
                            :class="form.quality === q.value ? 'border-accent bg-accent/10 ring-1 ring-accent' : 'border-line hover:border-muted'">
                            <input v-model="form.quality" type="radio" :value="q.value" class="sr-only" />
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm font-medium">{{ q.label }}</span>
                                <span class="text-sm font-semibold tabular-nums">≈ {{ formatNano(priceFor(q.value).expected) }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-muted">{{ q.hint }}</p>
                        </label>
                    </div>
                </section>

                <!-- References -->
                <section class="card p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-sm font-semibold">Reference images <span class="font-normal text-muted">· optional</span></h2>
                            <p class="mt-0.5 text-xs text-muted">Public image URLs to guide the result (max {{ pricing.max_reference_images }}). Each adds {{ pricing.tokens_per_reference_image }} input tokens to the price.</p>
                        </div>
                        <button type="button" class="btn-ghost shrink-0 !px-3 !py-1.5" :disabled="form.reference_images.length >= pricing.max_reference_images" @click="addRef">+ Add URL</button>
                    </div>
                    <div v-for="(_, i) in form.reference_images" :key="i" class="mt-3">
                        <div class="flex items-center gap-2">
                            <img v-if="/^https?:\/\//.test(form.reference_images[i])" :src="form.reference_images[i]" alt="" class="h-10 w-10 shrink-0 rounded-lg border border-line object-cover" @error="$event.target.style.display = 'none'" />
                            <input v-model="form.reference_images[i]" type="url" placeholder="https://example.com/photo.jpg" :aria-label="`Reference image ${i + 1}`" class="w-full rounded-xl border-line text-sm focus:border-accent focus:ring-accent" />
                            <button type="button" class="rounded-lg p-2 text-muted hover:bg-surface-2 hover:text-red-500" aria-label="Remove reference" @click="removeRef(i)">✕</button>
                        </div>
                        <p v-if="form.errors[`reference_images.${i}`]" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors[`reference_images.${i}`] }}</p>
                    </div>
                </section>
            </div>

            <!-- Price summary -->
            <aside>
                <div class="card sticky top-24 overflow-hidden">
                    <div class="bg-accent/10 p-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand">Estimated price</p>
                        <p class="font-display mt-1 text-5xl font-semibold tabular-nums">{{ formatNano(estimate.expected) }}</p>

                        <div class="mt-4">
                            <div class="relative h-1.5 rounded-full bg-line">
                                <div class="absolute inset-y-0 rounded-full bg-accent/40" style="left: 0; right: 0"></div>
                                <div class="absolute top-1/2 h-3.5 w-3.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-surface bg-accent shadow" :style="`left:${marker}%`"></div>
                            </div>
                            <div class="mt-2 flex justify-between text-xs tabular-nums text-muted">
                                <span>{{ formatNano(estimate.min) }}</span><span>likely range</span><span>{{ formatNano(estimate.max) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4 p-5 text-sm">
                        <p class="leading-relaxed text-muted">An estimate: the final price is set once the image is made. Includes a {{ pricing.markup_percent }}% service fee. <strong class="font-medium text-fg">Failed generations are free.</strong></p>

                        <div class="flex items-center justify-between border-t border-line pt-4">
                            <span class="text-muted">Available credits</span>
                            <span class="font-semibold tabular-nums" :class="canAfford ? '' : 'text-red-600 dark:text-red-400'">{{ formatNano(available_nano) }}</span>
                        </div>

                        <div v-if="!canAfford" class="rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-xs leading-relaxed text-red-700 dark:text-red-300">
                            You need at least {{ formatNano(estimate.max) }} available to cover the worst case.
                            <Link :href="route('credits.index')" class="font-semibold underline">Add credits</Link>
                        </div>

                        <button type="submit" :disabled="!ready" class="btn-accent w-full py-3 text-base">
                            <svg v-if="form.processing" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M12 3a9 9 0 019 9" stroke-linecap="round" /></svg>
                            {{ form.processing ? 'Submitting…' : 'Generate image' }}
                        </button>
                    </div>
                </div>
            </aside>
        </form>
    </AuthenticatedLayout>
</template>
