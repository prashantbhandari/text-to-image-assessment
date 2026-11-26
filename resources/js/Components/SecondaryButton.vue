<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-lg border border-line bg-surface px-4 py-2 text-sm font-semibold text-fg shadow-sm transition duration-150 ease-in-out hover:bg-surface-2 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 disabled:opacity-25"
    >
        <slot />
    </button>
</template>
