<script setup lang="ts">
export type PdfStatus = 'started' | 'running' | 'done';

defineProps<{
    status: PdfStatus;
}>();

const statusClasses: Record<PdfStatus, string> = {
    started:
        'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    running:
        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    done: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
};
</script>

<template>
    <span
        class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
        :class="statusClasses[status]"
    >
        {{ status }}
    </span>
</template>
