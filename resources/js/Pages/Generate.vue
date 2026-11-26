<script setup>
// Generate page: prompt, size, quality, reference URLs, and a live price estimate.
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { estimatePrice, formatNano } from '@/money';

const props = defineProps({
    pricing: Object,
    available_nano: Number, // balance minus worst-case reserve of in-flight jobs
});

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
});

const sizes = [
    { value: '1024x1024', label: 'Square', hint: '1024 × 1024' },
    { value: '1536x1024', label: 'Landscape', hint: '1536 × 1024' },
    { value: '1024x1536', label: 'Portrait', hint: '1024 × 1536' },
];
const qualities = [
    { value: 'low', label: 'Low', hint: 'Fast & cheap' },
    { value: 'medium', label: 'Medium', hint: 'Balanced' },
    { value: 'high', label: 'High', hint: 'Best detail' },
];

const filledRefs = computed(() => form.reference_images.filter((u) => u.trim() !== ''));
const estimate = computed(() =>
    estimatePrice(props.pricing, {
        prompt: form.prompt,
        size: form.size,
        quality: form.quality,
        referenceCount: filledRefs.value.length,
    }),
);
const canAfford = computed(() => props.available_nano >= estimate.value.max);

const addRef = () => {
    if (form.reference_images.length < props.pricing.max_reference_images) form.reference_images.push('');
};
const removeRef = (i) => form.reference_images.splice(i, 1);

const submit = () => {
    form.transform((d) => ({ ...d, reference_images: d.reference_images.filter((u) => u.trim() !== '') })).post(route('generations.store'));
};
</script>

<template>
    <Head title="Generate" />
    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-2xl font-semibold tracking-tight">Generate an image</h1>
            <p class="mt-1 text-sm text-slate-500">Describe what you want. You only pay when an image is successfully created.</p>
        </template>

        <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <div class="space-y-6 lg:col-span-2">
                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <label for="prompt" class="text-sm font-medium">Prompt</label>
                    <textarea id="prompt" v-model="form.prompt" rows="5" maxlength="4000" placeholder="A lighthouse on a cliff at dusk, oil painting…"
                        class="mt-2 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <div class="mt-1 flex justify-between text-xs text-slate-500">
                        <span class="text-red-600">{{ form.errors.prompt }}</span>
                        <span>{{ [...form.prompt].length }} / 4000</span>
                    </div>
                </section>

                <section class="grid gap-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-2">
                    <fieldset>
                        <legend class="text-sm font-medium">Size</legend>
                        <div class="mt-2 space-y-2">
                            <label v-for="s in sizes" :key="s.value" class="flex cursor-pointer items-center justify-between rounded-lg border px-3 py-2 text-sm"
                                :class="form.size === s.value ? 'border-indigo-600 bg-indigo-50' : 'border-slate-200 hover:border-slate-300'">
                                <span><input v-model="form.size" type="radio" :value="s.value" class="mr-2 text-indigo-600 focus:ring-indigo-500" />{{ s.label }}</span>
                                <span class="text-xs text-slate-500">{{ s.hint }}</span>
                            </label>
                        </div>
                    </fieldset>
                    <fieldset>
                        <legend class="text-sm font-medium">Quality</legend>
                        <div class="mt-2 space-y-2">
                            <label v-for="q in qualities" :key="q.value" class="flex cursor-pointer items-center justify-between rounded-lg border px-3 py-2 text-sm"
                                :class="form.quality === q.value ? 'border-indigo-600 bg-indigo-50' : 'border-slate-200 hover:border-slate-300'">
                                <span><input v-model="form.quality" type="radio" :value="q.value" class="mr-2 text-indigo-600 focus:ring-indigo-500" />{{ q.label }}</span>
                                <span class="text-xs text-slate-500">{{ q.hint }}</span>
                            </label>
                        </div>
                    </fieldset>
                </section>

                <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-sm font-medium">Reference images <span class="font-normal text-slate-500">(optional)</span></h2>
                            <p class="text-xs text-slate-500">Public image URLs, up to {{ pricing.max_reference_images }}. Each adds {{ pricing.tokens_per_reference_image }} input tokens.</p>
                        </div>
                        <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium hover:bg-slate-50 disabled:opacity-50"
                            :disabled="form.reference_images.length >= pricing.max_reference_images" @click="addRef">+ Add URL</button>
                    </div>
                    <div v-for="(_, i) in form.reference_images" :key="i" class="mt-3">
                        <div class="flex gap-2">
                            <input v-model="form.reference_images[i]" type="url" placeholder="https://example.com/photo.jpg" :aria-label="`Reference image ${i + 1}`"
                                class="w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <button type="button" class="rounded-md px-2 text-slate-500 hover:bg-slate-100 hover:text-red-600" aria-label="Remove" @click="removeRef(i)">✕</button>
                        </div>
                        <p v-if="form.errors[`reference_images.${i}`]" class="mt-1 text-xs text-red-600">{{ form.errors[`reference_images.${i}`] }}</p>
                    </div>
                </section>
            </div>

            <aside class="lg:col-span-1">
                <div class="sticky top-24 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-sm font-medium text-slate-500">Estimated cost</h2>
                    <p class="mt-1 text-3xl font-semibold tracking-tight">{{ formatNano(estimate.expected) }}</p>
                    <p class="mt-1 text-xs text-slate-500">Likely range {{ formatNano(estimate.min) }} – {{ formatNano(estimate.max) }}</p>
                    <p class="mt-3 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-600">
                        This is an estimate. The final price is set after the image is made, once the exact reasoning usage is known. Includes a {{ pricing.markup_percent }}% service fee. Failed generations are free.
                    </p>

                    <div class="mt-4 flex items-center justify-between text-sm">
                        <span class="text-slate-500">Available</span>
                        <span class="font-medium" :class="canAfford ? 'text-emerald-700' : 'text-red-600'">{{ formatNano(available_nano) }}</span>
                    </div>
                    <p v-if="!canAfford" class="mt-2 text-xs text-red-600">
                        Not enough credits to cover the worst case ({{ formatNano(estimate.max) }}).
                        <Link :href="route('credits.index')" class="font-medium underline">Buy credits</Link>
                    </p>

                    <button type="submit" :disabled="form.processing || !form.prompt.trim() || !canAfford"
                        class="mt-4 w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ form.processing ? 'Submitting…' : 'Generate' }}
                    </button>
                </div>
            </aside>
        </form>
    </AuthenticatedLayout>
</template>
