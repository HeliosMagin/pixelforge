<script setup lang="ts">
import type { Generation } from '@/types';
import { CheckCircle2, Clock, Loader2, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ generation: Pick<Generation, 'status' | 'status_detail'> }>();

const view = computed(() => {
    switch (props.generation.status) {
        case 'pending':
        case 'queued':
            return {
                label: 'Queued',
                detail: props.generation.status_detail === 'waiting_for_capacity' ? 'waiting for Pixelforge capacity' : null,
                icon: Clock,
                spin: false,
                classes: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
            };
        case 'processing':
            return {
                label: 'Processing',
                detail: null,
                icon: Loader2,
                spin: true,
                classes: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
            };
        case 'succeeded':
            return {
                label: 'Succeeded',
                detail: null,
                icon: CheckCircle2,
                spin: false,
                classes: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
            };
        default:
            return {
                label: 'Failed',
                detail: null,
                icon: XCircle,
                spin: false,
                classes: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
            };
    }
});
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="view.classes">
        <component :is="view.icon" class="size-3.5" :class="{ 'animate-spin': view.spin }" />
        {{ view.label }}
        <span v-if="view.detail" class="font-normal opacity-80">· {{ view.detail }}</span>
    </span>
</template>
