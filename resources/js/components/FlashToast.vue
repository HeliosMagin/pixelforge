<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const page = usePage<SharedData>();
const message = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => page.props.flash?.success,
    (value) => {
        if (!value) return;
        message.value = value;
        clearTimeout(timer);
        timer = setTimeout(() => (message.value = null), 4000);
    },
    { immediate: true },
);
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-2 opacity-0"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="translate-y-2 opacity-0"
    >
        <div
            v-if="message"
            role="status"
            class="fixed bottom-4 right-4 z-50 flex max-w-sm items-start gap-3 rounded-lg border bg-background px-4 py-3 text-sm shadow-lg"
        >
            <CheckCircle2 class="mt-0.5 size-4 shrink-0 text-emerald-600" />
            <p class="flex-1">{{ message }}</p>
            <button type="button" class="text-muted-foreground hover:text-foreground" aria-label="Dismiss" @click="message = null">
                <X class="size-4" />
            </button>
        </div>
    </Transition>
</template>
