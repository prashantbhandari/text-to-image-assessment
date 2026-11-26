<script setup>
// Prev/next controls for a Laravel paginator passed through Inertia.
import { Link } from '@inertiajs/vue3';

defineProps({ page: Object }); // { current_page, last_page, from, to, total, prev_page_url, next_page_url }
</script>

<template>
    <div v-if="page.last_page > 1" class="flex items-center justify-between border-t border-line px-4 py-3 text-sm">
        <p class="text-muted tabular-nums">{{ page.from }}–{{ page.to }} of {{ page.total }}</p>
        <div class="flex gap-2">
            <Link v-if="page.prev_page_url" :href="page.prev_page_url" preserve-scroll class="btn-ghost !px-3 !py-1.5">← Previous</Link>
            <span v-else class="btn-ghost pointer-events-none !px-3 !py-1.5 opacity-40">← Previous</span>
            <Link v-if="page.next_page_url" :href="page.next_page_url" preserve-scroll class="btn-ghost !px-3 !py-1.5">Next →</Link>
            <span v-else class="btn-ghost pointer-events-none !px-3 !py-1.5 opacity-40">Next →</span>
        </div>
    </div>
</template>
