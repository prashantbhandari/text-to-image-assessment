<script setup>
// Status chip with a colored dot. `processing` and `queued` pulse to show life.
defineProps({ status: String });

const styles = {
    queued: ['bg-surface-2 text-muted ring-line', 'bg-muted', 'Queued'],
    processing: ['bg-amber-500/10 text-amber-700 dark:text-amber-300 ring-amber-500/30', 'bg-amber-500 animate-pulse', 'Processing'],
    succeeded: ['bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 ring-emerald-500/30', 'bg-emerald-500', 'Done'],
    failed: ['bg-red-500/10 text-red-700 dark:text-red-300 ring-red-500/30', 'bg-red-500', 'Failed'],
};
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1" :class="styles[status][0]">
        <span class="h-1.5 w-1.5 rounded-full" :class="styles[status][1]"></span>{{ styles[status][2] }}
    </span>
</template>
