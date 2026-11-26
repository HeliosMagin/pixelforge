<script setup lang="ts">
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { formatExact, formatMoney } from '@/lib/money';
import type { Generation } from '@/types';
import { Link } from '@inertiajs/vue3';
import { useNow } from '@vueuse/core';
import { ImageOff, Loader2, RotateCcw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ generation: Generation }>();

const now = useNow({ interval: 1000 });
const imageFailed = ref(false);

const imageExpired = computed(
    () => imageFailed.value || (props.generation.image_url_expires_at !== null && new Date(props.generation.image_url_expires_at) <= now.value),
);

const aspect = computed(() => ({ '1536x1024': 'aspect-[3/2]', '1024x1536': 'aspect-[2/3]' })[props.generation.size] ?? 'aspect-square');

/** "12s", "1m 05s": time spent so far, or in total once finished. */
const elapsed = computed(() => {
    const start = new Date(props.generation.created_at).getTime();
    const end = props.generation.completed_at ? new Date(props.generation.completed_at).getTime() : now.value.getTime();
    const seconds = Math.max(0, Math.round((end - start) / 1000));
    return seconds < 60 ? `${seconds}s` : `${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s`;
});

const createdAt = computed(() =>
    new Date(props.generation.created_at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }),
);
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-xl border bg-card shadow-sm">
        <!-- Image area -->
        <div class="relative flex items-center justify-center bg-muted" :class="aspect">
            <a
                v-if="generation.status === 'succeeded' && generation.image_url && !imageExpired"
                :href="generation.image_url"
                target="_blank"
                rel="noopener"
            >
                <img
                    :src="generation.image_url"
                    :alt="generation.prompt"
                    class="absolute inset-0 size-full object-cover"
                    loading="lazy"
                    @error="imageFailed = true"
                />
            </a>
            <div v-else-if="generation.status === 'succeeded'" class="flex flex-col items-center gap-2 p-4 text-center text-xs text-muted-foreground">
                <ImageOff class="size-6" />
                Image link expired (Pixelforge keeps images for 24 hours)
            </div>
            <div v-else-if="generation.status === 'failed'" class="flex flex-col items-center gap-2 p-4 text-center text-xs text-muted-foreground">
                <ImageOff class="size-6" />
                No image
            </div>
            <div v-else class="flex flex-col items-center gap-2 text-xs text-muted-foreground">
                <Loader2 class="size-6 animate-spin" />
                {{ generation.status === 'processing' ? 'Generating…' : 'Waiting in queue…' }}
                <span class="tabular-nums">{{ elapsed }}</span>
            </div>
        </div>

        <!-- Details -->
        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <StatusBadge :generation="generation" />
                <span class="text-xs text-muted-foreground">{{ createdAt }}</span>
            </div>

            <p class="line-clamp-3 text-sm" :title="generation.prompt">{{ generation.prompt }}</p>
            <p class="text-xs text-muted-foreground">
                {{ generation.size.replace('x', '×') }} · <span class="capitalize">{{ generation.quality }}</span>
                <template v-if="generation.reference_images.length"> · {{ generation.reference_images.length }} reference(s)</template>
                <template v-if="generation.is_terminal"> · {{ elapsed }}</template>
            </p>

            <div
                v-if="generation.error"
                class="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300"
            >
                <p>{{ generation.error.message }}</p>
                <p class="mt-1 font-medium">Free: you weren't charged.</p>
            </div>

            <dl class="mt-auto grid grid-cols-2 gap-2 border-t pt-3 text-xs">
                <div>
                    <dt class="text-muted-foreground">Estimated</dt>
                    <dd class="tabular-nums" :title="`Up to ${formatMoney(generation.max_nanos)}`">
                        ≈ {{ formatMoney(generation.estimated_nanos) }}
                        <span class="text-muted-foreground">(max {{ formatMoney(generation.max_nanos) }})</span>
                    </dd>
                </div>
                <div class="text-right">
                    <dt class="text-muted-foreground">Actual</dt>
                    <dd class="font-medium tabular-nums">
                        <span v-if="generation.status === 'succeeded'" :title="formatExact(generation.charged_nanos)">{{
                            formatMoney(generation.charged_nanos)
                        }}</span>
                        <span v-else-if="generation.status === 'failed'">$0.00</span>
                        <span v-else class="font-normal text-muted-foreground">when finished</span>
                    </dd>
                </div>
            </dl>

            <Button v-if="generation.error?.retryable" as-child variant="outline" size="sm">
                <Link :href="route('generations.create', { from: generation.id })"><RotateCcw /> Try again</Link>
            </Button>
        </div>
    </article>
</template>
