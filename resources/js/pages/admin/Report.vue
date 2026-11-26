<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatExact, formatMoney } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, ChevronRight } from 'lucide-vue-next';
import { reactive, ref } from 'vue';

interface Totals {
    generations: number;
    charged_nanos: number;
    provider_nanos: number;
    profit_nanos: number;
}
interface UserSummary {
    id: number | null;
    name: string;
    email: string | null;
}
interface Day extends Totals {
    date: string;
    pixelforge_generations: number | null;
    pixelforge_cost_nanos: number | null;
    users: (Totals & { user: UserSummary })[];
}

const props = defineProps<{
    range: { start: string; end: string };
    report: {
        days: Day[];
        users: (Totals & { user: UserSummary })[];
        total: Totals & { pixelforge_generations: number | null; pixelforge_cost_nanos: number | null };
    };
    usageError: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin report', href: '/admin/report' }];
const filters = reactive({ ...props.range });
const expanded = ref<Set<string>>(new Set());

function apply() {
    router.get(route('admin.report'), filters, { preserveScroll: true });
}

function toggle(date: string) {
    const next = new Set(expanded.value);
    if (next.has(date)) next.delete(date);
    else next.add(date);
    expanded.value = next;
}

const diff = (ours: number, theirs: number | null) => (theirs === null ? null : ours - theirs);
</script>

<template>
    <Head title="Admin report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4 lg:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Spend & profit</h1>
                    <p class="text-sm text-muted-foreground">
                        By UTC day of completion, which is how Pixelforge bills. Provider cost is checked against <code>GET /v1/usage</code> for this
                        environment's API key.
                    </p>
                </div>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
                    <div class="space-y-1">
                        <Label for="start" class="text-xs">From</Label>
                        <Input id="start" v-model="filters.start" type="date" class="h-8" />
                    </div>
                    <div class="space-y-1">
                        <Label for="end" class="text-xs">To</Label>
                        <Input id="end" v-model="filters.end" type="date" class="h-8" />
                    </div>
                    <Button type="submit" size="sm">Apply</Button>
                </form>
            </div>

            <div
                v-if="usageError"
                class="flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10"
            >
                <AlertTriangle class="size-4" /> {{ usageError }}
            </div>

            <!-- Totals -->
            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Credits spent by users</p>
                    <p class="text-2xl font-semibold tabular-nums" :title="formatExact(report.total.charged_nanos)">
                        {{ formatMoney(report.total.charged_nanos) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Provider cost (ours)</p>
                    <p class="text-2xl font-semibold tabular-nums" :title="formatExact(report.total.provider_nanos)">
                        {{ formatMoney(report.total.provider_nanos) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Provider cost (Pixelforge)</p>
                    <p class="text-2xl font-semibold tabular-nums" :title="formatExact(report.total.pixelforge_cost_nanos)">
                        {{ formatMoney(report.total.pixelforge_cost_nanos) }}
                    </p>
                    <p
                        v-if="report.total.pixelforge_cost_nanos !== null"
                        class="mt-1 flex items-center gap-1 text-xs"
                        :class="
                            diff(report.total.provider_nanos, report.total.pixelforge_cost_nanos) === 0
                                ? 'text-emerald-700 dark:text-emerald-400'
                                : 'text-red-600'
                        "
                    >
                        <CheckCircle2 v-if="diff(report.total.provider_nanos, report.total.pixelforge_cost_nanos) === 0" class="size-3.5" />
                        <AlertTriangle v-else class="size-3.5" />
                        {{
                            diff(report.total.provider_nanos, report.total.pixelforge_cost_nanos) === 0
                                ? 'Matches our records exactly'
                                : `Off by ${formatExact(diff(report.total.provider_nanos, report.total.pixelforge_cost_nanos))}`
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Profit</p>
                    <p class="text-2xl font-semibold tabular-nums" :title="formatExact(report.total.profit_nanos)">
                        {{ formatMoney(report.total.profit_nanos) }}
                    </p>
                </div>
            </section>

            <!-- Per day -->
            <section class="space-y-2">
                <h2 class="font-medium">By day</h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Day (UTC)</th>
                                <th class="px-4 py-2 text-right font-medium">Billed images</th>
                                <th class="px-4 py-2 text-right font-medium">Credits spent</th>
                                <th class="px-4 py-2 text-right font-medium">Provider cost</th>
                                <th class="px-4 py-2 text-right font-medium">Pixelforge reports</th>
                                <th class="px-4 py-2 text-right font-medium">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <template v-for="day in report.days" :key="day.date">
                                <tr
                                    :class="day.users.length ? 'cursor-pointer hover:bg-muted/30' : 'text-muted-foreground'"
                                    @click="day.users.length && toggle(day.date)"
                                >
                                    <td class="whitespace-nowrap px-4 py-2">
                                        <span class="inline-flex items-center gap-1">
                                            <ChevronRight
                                                class="size-3.5 transition-transform"
                                                :class="[expanded.has(day.date) && 'rotate-90', !day.users.length && 'invisible']"
                                            />
                                            {{ day.date }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">
                                        {{ day.generations }}
                                        <span
                                            v-if="day.pixelforge_generations !== null && day.pixelforge_generations !== day.generations"
                                            class="text-amber-600"
                                        >
                                            (PF: {{ day.pixelforge_generations }})
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums" :title="formatExact(day.charged_nanos)">
                                        {{ formatMoney(day.charged_nanos) }}
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums" :title="formatExact(day.provider_nanos)">
                                        {{ formatMoney(day.provider_nanos) }}
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums" :title="formatExact(day.pixelforge_cost_nanos)">
                                        <span :class="diff(day.provider_nanos, day.pixelforge_cost_nanos) ? 'font-medium text-red-600' : ''">
                                            {{ formatMoney(day.pixelforge_cost_nanos) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums" :title="formatExact(day.profit_nanos)">
                                        {{ formatMoney(day.profit_nanos) }}
                                    </td>
                                </tr>
                                <tr
                                    v-for="row in expanded.has(day.date) ? day.users : []"
                                    :key="`${day.date}-${row.user.id}`"
                                    class="bg-muted/20 text-xs"
                                >
                                    <td class="py-1.5 pl-10 pr-4">
                                        {{ row.user.name }} <span class="text-muted-foreground">{{ row.user.email }}</span>
                                    </td>
                                    <td class="px-4 py-1.5 text-right tabular-nums">{{ row.generations }}</td>
                                    <td class="px-4 py-1.5 text-right tabular-nums">{{ formatMoney(row.charged_nanos) }}</td>
                                    <td class="px-4 py-1.5 text-right tabular-nums">{{ formatMoney(row.provider_nanos) }}</td>
                                    <td />
                                    <td class="px-4 py-1.5 text-right tabular-nums">{{ formatMoney(row.profit_nanos) }}</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Per user -->
            <section class="space-y-2">
                <h2 class="font-medium">By user</h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">User</th>
                                <th class="px-4 py-2 text-right font-medium">Billed images</th>
                                <th class="px-4 py-2 text-right font-medium">Credits spent</th>
                                <th class="px-4 py-2 text-right font-medium">Provider cost</th>
                                <th class="px-4 py-2 text-right font-medium">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-if="report.users.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">No billed generations in this range.</td>
                            </tr>
                            <tr v-for="row in report.users" :key="row.user.id ?? row.user.name">
                                <td class="px-4 py-2">
                                    {{ row.user.name }} <span class="text-xs text-muted-foreground">{{ row.user.email }}</span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ row.generations }}</td>
                                <td class="px-4 py-2 text-right tabular-nums" :title="formatExact(row.charged_nanos)">
                                    {{ formatMoney(row.charged_nanos) }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums" :title="formatExact(row.provider_nanos)">
                                    {{ formatMoney(row.provider_nanos) }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums" :title="formatExact(row.profit_nanos)">
                                    {{ formatMoney(row.profit_nanos) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
