<script setup>
// Brand mark: a camera aperture on an ember tile. `size` is a Tailwind size pair.
defineProps({ size: { type: String, default: 'h-9 w-9' } });
</script>

<template>
    <span :class="size" class="grid shrink-0 place-items-center rounded-xl bg-accent text-accent-fg shadow-lg shadow-accent/25">
        <svg class="h-3/5 w-3/5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10" />
            <path d="m14.31 8 5.74 9.94M9.69 8h11.48M7.38 12l5.74-9.94M9.69 16 3.95 6.06M14.31 16H2.83m13.79-4-5.74 9.94" />
        </svg>
    </span>
</template>
