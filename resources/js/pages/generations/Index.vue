<script setup lang="ts">
import GenerationCard from '@/components/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, Generation, ResourcePage } from '@/types';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Sparkles } from 'lucide-vue-next';
import { computed, watch } from 'vue';

const props = defineProps<{ generations: ResourcePage<Generation> }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: '/generations' }];

// Refresh live status, but only while something on this page is still in flight.
const hasActive = computed(() => props.generations.data.some((g) => !g.is_terminal));
const { start, stop } = usePoll(2000, { only: ['generations', 'auth'] }, { autoStart: false });

watch(hasActive, (active) => (active ? start() : stop()), { immediate: true });
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4 lg:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Your generations</h1>
                    <p class="text-sm text-muted-foreground">
                        Status updates live.
                        <template v-if="hasActive">Watching {{ generations.data.filter((g) => !g.is_terminal).length }} in progress…</template>
                    </p>
                </div>
                <Button as-child>
                    <Link :href="route('generations.create')"><Sparkles /> New generation</Link>
                </Button>
            </div>

            <div v-if="generations.data.length === 0" class="flex flex-col items-center gap-3 rounded-xl border border-dashed py-16 text-center">
                <Sparkles class="size-8 text-muted-foreground" />
                <p class="font-medium">No generations yet</p>
                <p class="max-w-sm text-sm text-muted-foreground">Write a prompt and you'll see it here, with its status, price and image.</p>
                <Button as-child variant="outline">
                    <Link :href="route('generations.create')">Create your first image</Link>
                </Button>
            </div>

            <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                <GenerationCard v-for="generation in generations.data" :key="generation.id" :generation="generation" />
            </div>

            <nav v-if="generations.meta.last_page > 1" class="flex items-center justify-between">
                <Button as-child variant="outline" size="sm" :disabled="!generations.links.prev">
                    <Link v-if="generations.links.prev" :href="generations.links.prev" preserve-scroll><ChevronLeft /> Newer</Link>
                    <span v-else class="pointer-events-none opacity-50"><ChevronLeft /> Newer</span>
                </Button>
                <span class="text-sm text-muted-foreground">Page {{ generations.meta.current_page }} of {{ generations.meta.last_page }}</span>
                <Button as-child variant="outline" size="sm">
                    <Link v-if="generations.links.next" :href="generations.links.next" preserve-scroll>Older <ChevronRight /></Link>
                    <span v-else class="pointer-events-none opacity-50">Older <ChevronRight /></span>
                </Button>
            </nav>
        </div>
    </AppLayout>
</template>
