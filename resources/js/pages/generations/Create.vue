<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatBalance, formatExact, formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem, Estimate, SharedData, TokenKind } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { ChevronDown, ImagePlus, Info, Loader2, Sparkles, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    sizes: string[];
    qualities: string[];
    maxPromptLength: number;
    maxReferenceImages: number;
    initial: { prompt: string; size: string; quality: string; reference_images: string[] };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/generate' }];
const page = usePage<SharedData>();

const form = useForm({
    prompt: props.initial.prompt,
    size: props.initial.size,
    quality: props.initial.quality,
    reference_images: [...props.initial.reference_images] as string[],
});

const sizeLabels: Record<string, { label: string; aspect: string }> = {
    '1024x1024': { label: 'Square', aspect: 'aspect-square w-5' },
    '1536x1024': { label: 'Landscape', aspect: 'aspect-[3/2] w-6' },
    '1024x1536': { label: 'Portrait', aspect: 'aspect-[2/3] w-4' },
};
const qualityHints: Record<string, string> = { low: 'Fast draft', medium: 'Balanced', high: 'Most detail' };

// --- Live estimate -------------------------------------------------------------------------
const estimate = ref<Estimate | null>(null);
const estimateError = ref<string | null>(null);
const estimating = ref(false);
const showBreakdown = ref(false);
let requestId = 0;

const fetchEstimate = useDebounceFn(async () => {
    const id = ++requestId;
    const params = new URLSearchParams({ prompt: form.prompt, size: form.size, quality: form.quality });
    form.reference_images.filter((url) => url.trim()).forEach((url) => params.append('reference_images[]', url.trim()));

    estimating.value = true;
    try {
        const response = await fetch(`${route('generations.estimate')}?${params}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const body = await response.json();
        if (id !== requestId) return;

        if (response.ok) {
            estimate.value = body;
            estimateError.value = null;
        } else {
            estimateError.value = response.status === 422 ? null : (body.message ?? "Couldn't calculate the price.");
        }
    } catch {
        if (id === requestId) estimateError.value = "Couldn't reach the server to calculate the price.";
    } finally {
        if (id === requestId) estimating.value = false;
    }
}, 250);

watch(() => [form.prompt, form.size, form.quality, [...form.reference_images]], fetchEstimate, { immediate: true });

const balance = computed(() => page.props.auth.user.balance_nanos);
const affordable = computed(() => (estimate.value ? balance.value >= estimate.value.max_nanos : true));
const promptLength = computed(() => [...form.prompt].length);
const canSubmit = computed(
    () => form.prompt.trim().length > 0 && promptLength.value <= props.maxPromptLength && affordable.value && !form.processing,
);

const tokenRows: { kind: TokenKind; label: string; hint: string }[] = [
    { kind: 'input_text_tokens', label: 'Prompt text', hint: '≈ 1 token per 4 characters, ±15%' },
    { kind: 'input_image_tokens', label: 'Reference images', hint: '765 tokens each' },
    { kind: 'output_tokens', label: 'Image output', hint: 'Fixed by size and quality' },
    { kind: 'reasoning_tokens', label: 'Reasoning', hint: 'Only known once the image is done' },
];

function tokenRange(kind: TokenKind): string {
    const t = estimate.value?.tokens[kind];
    if (!t) return '—';
    const fmt = (n: number) => n.toLocaleString('en-US');
    return t.min === t.max ? fmt(t.min) : `${fmt(t.min)}–${fmt(t.max)}`;
}

// --- Reference images ------------------------------------------------------------------------
function addReference() {
    if (form.reference_images.length < props.maxReferenceImages) form.reference_images.push('');
}
function removeReference(index: number) {
    form.reference_images.splice(index, 1);
}
// Errors for fields that aren't in the form itself (array items, the balance check).
const extraErrors = computed(() => form.errors as Record<string, string | undefined>);
const referenceError = (index: number) => extraErrors.value[`reference_images.${index}`];

function submit() {
    form.post(route('generations.store'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="grid flex-1 gap-6 p-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:p-6" @submit.prevent="submit">
            <!-- Left: what to generate -->
            <div class="space-y-6">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Create an image</h1>
                    <p class="text-sm text-muted-foreground">Describe it, pick a format, and see the price before you submit.</p>
                </div>

                <div class="space-y-2">
                    <div class="flex items-end justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span class="text-xs tabular-nums" :class="promptLength > maxPromptLength ? 'text-red-600' : 'text-muted-foreground'">
                            {{ promptLength.toLocaleString() }} / {{ maxPromptLength.toLocaleString() }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="6"
                        autofocus
                        placeholder="A lighthouse on a basalt cliff at dusk, long exposure, soft fog…"
                        class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        @keydown.meta.enter="submit"
                        @keydown.ctrl.enter="submit"
                    />
                    <InputError :message="form.errors.prompt" />
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <fieldset class="space-y-2">
                        <legend class="text-sm font-medium">Size</legend>
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                v-for="size in sizes"
                                :key="size"
                                type="button"
                                :class="
                                    cn(
                                        'flex flex-col items-center gap-2 rounded-lg border px-2 py-3 text-xs transition-colors hover:bg-accent',
                                        form.size === size && 'border-primary bg-accent ring-1 ring-primary',
                                    )
                                "
                                :aria-pressed="form.size === size"
                                @click="form.size = size"
                            >
                                <span :class="['rounded-sm border-2 border-current opacity-60', sizeLabels[size]?.aspect]" />
                                <span class="font-medium">{{ sizeLabels[size]?.label ?? size }}</span>
                                <span class="text-muted-foreground">{{ size.replace('x', '×') }}</span>
                            </button>
                        </div>
                    </fieldset>

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-medium">Quality</legend>
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                v-for="quality in qualities"
                                :key="quality"
                                type="button"
                                :class="
                                    cn(
                                        'flex flex-col items-center gap-1 rounded-lg border px-2 py-3 text-xs transition-colors hover:bg-accent',
                                        form.quality === quality && 'border-primary bg-accent ring-1 ring-primary',
                                    )
                                "
                                :aria-pressed="form.quality === quality"
                                @click="form.quality = quality"
                            >
                                <span class="font-medium capitalize">{{ quality }}</span>
                                <span class="text-muted-foreground">{{ qualityHints[quality] }}</span>
                            </button>
                        </div>
                    </fieldset>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium">Reference images <span class="font-normal text-muted-foreground">(optional)</span></p>
                            <p class="text-xs text-muted-foreground">Public http(s) image URLs, up to {{ maxReferenceImages }}.</p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="form.reference_images.length >= maxReferenceImages"
                            @click="addReference"
                        >
                            <ImagePlus /> Add URL
                        </Button>
                    </div>
                    <div v-for="(_, index) in form.reference_images" :key="index" class="space-y-1">
                        <div class="flex gap-2">
                            <Input v-model="form.reference_images[index]" type="url" placeholder="https://example.com/reference.png" />
                            <Button type="button" variant="ghost" size="icon" aria-label="Remove reference image" @click="removeReference(index)">
                                <Trash2 />
                            </Button>
                        </div>
                        <InputError :message="referenceError(index)" />
                    </div>
                    <InputError :message="form.errors.reference_images" />
                </div>
            </div>

            <!-- Right: what it costs -->
            <aside class="lg:sticky lg:top-4 lg:self-start">
                <div class="space-y-4 rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-medium text-muted-foreground">Estimated price</h2>
                        <Loader2 v-if="estimating" class="size-4 animate-spin text-muted-foreground" />
                    </div>

                    <div v-if="estimate">
                        <p class="text-3xl font-semibold tabular-nums" :title="formatExact(estimate.expected_nanos)">
                            ≈ {{ formatMoney(estimate.expected_nanos) }}
                        </p>
                        <p class="mt-1 text-sm tabular-nums text-muted-foreground">
                            between {{ formatMoney(estimate.min_nanos) }} and
                            <span class="font-medium text-foreground">{{ formatMoney(estimate.max_nanos) }}</span>
                        </p>
                    </div>
                    <div v-else class="h-14 animate-pulse rounded-md bg-muted" />

                    <p v-if="estimateError" class="text-sm text-red-600">{{ estimateError }}</p>

                    <div class="flex gap-2 rounded-lg bg-muted/60 p-3 text-xs text-muted-foreground">
                        <Info class="mt-0.5 size-3.5 shrink-0" />
                        <p>
                            You pay what the image actually costs, <strong class="text-foreground">never more than the maximum</strong>. Part of the
                            cost is only known when it finishes. If it fails, it's free.
                        </p>
                    </div>

                    <div v-if="estimate">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between text-xs font-medium text-muted-foreground hover:text-foreground"
                            :aria-expanded="showBreakdown"
                            @click="showBreakdown = !showBreakdown"
                        >
                            How this is calculated
                            <ChevronDown class="size-4 transition-transform" :class="{ 'rotate-180': showBreakdown }" />
                        </button>
                        <table v-if="showBreakdown" class="mt-3 w-full text-xs">
                            <tbody class="divide-y">
                                <tr v-for="row in tokenRows" :key="row.kind">
                                    <td class="py-1.5 pr-2">
                                        <p class="font-medium">{{ row.label }}</p>
                                        <p class="text-muted-foreground">{{ row.hint }}</p>
                                    </td>
                                    <td class="py-1.5 text-right tabular-nums">
                                        <p>{{ tokenRange(row.kind) }} tokens</p>
                                        <p class="text-muted-foreground">× ${{ estimate.rates[row.kind] }} / 1M</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="py-1.5" colspan="2">
                                        <p class="text-muted-foreground">
                                            Pixelforge's token prices plus our {{ estimate.markup_percent }}% service fee.
                                        </p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="space-y-1 border-t pt-4 text-sm">
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">Your balance</span>
                            <span class="font-medium tabular-nums">{{ formatBalance(balance) }}</span>
                        </div>
                        <p v-if="estimate && affordable" class="text-xs text-muted-foreground">
                            We reserve {{ formatMoney(estimate.max_nanos) }} while it runs and return what isn't used.
                        </p>
                    </div>

                    <div
                        v-if="!affordable || extraErrors.balance"
                        class="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10"
                    >
                        <p>
                            {{ extraErrors.balance ?? `You need at least ${formatMoney(estimate?.max_nanos)} available to cover the maximum price.` }}
                        </p>
                        <Button as-child size="sm" variant="outline">
                            <Link :href="route('billing.index')">Buy credits</Link>
                        </Button>
                    </div>

                    <Button type="submit" class="w-full" size="lg" :disabled="!canSubmit">
                        <Loader2 v-if="form.processing" class="animate-spin" />
                        <Sparkles v-else />
                        Generate
                    </Button>
                </div>
            </aside>
        </form>
    </AppLayout>
</template>
