<script setup>
// Shows one-shot server messages (e.g. "Too many attempts") from the shared `flash.error` prop.
// Auto-dismisses after 6s; announced to screen readers via role="alert".
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const message = ref(null);
let timer;

watch(
    () => page.props.flash?.error,
    (value) => {
        clearTimeout(timer);
        message.value = value || null;
        if (value) timer = setTimeout(() => (message.value = null), 6000);
    },
    { immediate: true },
);
</script>

<template>
    <Transition enter-active-class="transition duration-200" enter-from-class="translate-y-2 opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
        <div v-if="message" role="alert" class="fixed bottom-5 left-1/2 z-[70] flex w-[min(92vw,26rem)] -translate-x-1/2 items-start gap-3 rounded-xl border border-red-500/30 bg-surface p-4 shadow-card">
            <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-red-500/15 text-xs font-bold text-red-500">!</span>
            <p class="flex-1 text-sm">{{ message }}</p>
            <button class="text-muted hover:text-fg" aria-label="Dismiss" @click="message = null">✕</button>
        </div>
    </Transition>
</template>
