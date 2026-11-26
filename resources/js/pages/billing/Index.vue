<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatBalance, formatExact, formatMoney } from '@/lib/money';
import type { BreadcrumbItem, Paginator, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Loader2, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Transaction {
    id: number;
    type: 'purchase' | 'hold' | 'hold_release' | 'charge';
    amount_nanos: number;
    balance_after_nanos: number;
    description: string | null;
    generation: { id: string; prompt: string } | null;
    created_at: string;
}

defineProps<{ packs: number[]; transactions: Paginator<Transaction> }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Billing', href: '/billing' }];
const page = usePage<SharedData>();
const balance = computed(() => page.props.auth.user.balance_nanos);
const buying = ref<number | null>(null);

const labels: Record<Transaction['type'], string> = {
    purchase: 'Credits purchased',
    hold: 'Reserved (max price)',
    hold_release: 'Reservation returned',
    charge: 'Charged',
};

function buy(amount: number) {
    buying.value = amount;
    router.post(route('billing.store'), { amount }, { preserveScroll: true, onFinish: () => (buying.value = null) });
}

const date = (iso: string) => new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
</script>

<template>
    <Head title="Billing" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4 lg:p-6">
            <section class="grid gap-4 md:grid-cols-[18rem_minmax(0,1fr)]">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Available balance</p>
                    <p class="mt-1 text-3xl font-semibold tabular-nums" :title="formatExact(balance)">{{ formatBalance(balance) }}</p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Credits are US dollars. Prices are fractions of a cent, so your balance shows up to 4 decimals.
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm font-medium">Buy credits</p>
                    <p class="text-xs text-muted-foreground">Demo only: no payment is taken.</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <Button v-for="pack in packs" :key="pack" variant="outline" :disabled="buying !== null" @click="buy(pack)">
                            <Loader2 v-if="buying === pack" class="animate-spin" />
                            <Plus v-else />
                            ${{ pack }}.00
                        </Button>
                    </div>
                </div>
            </section>

            <section class="space-y-3">
                <div>
                    <h2 class="font-medium">Activity</h2>
                    <p class="text-sm text-muted-foreground">
                        While an image is being made we reserve its maximum price. When it finishes we return the reservation and charge the actual
                        price, or nothing if it failed.
                    </p>
                </div>

                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">When</th>
                                <th class="px-4 py-2 font-medium">What</th>
                                <th class="px-4 py-2 text-right font-medium">Amount</th>
                                <th class="px-4 py-2 text-right font-medium">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-if="transactions.data.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">
                                    No activity yet. Buy some credits to get started.
                                </td>
                            </tr>
                            <tr v-for="t in transactions.data" :key="t.id">
                                <td class="whitespace-nowrap px-4 py-2 text-muted-foreground">{{ date(t.created_at) }}</td>
                                <td class="px-4 py-2">
                                    <p>{{ labels[t.type] }}</p>
                                    <p v-if="t.generation" class="max-w-md truncate text-xs text-muted-foreground">“{{ t.generation.prompt }}”</p>
                                </td>
                                <td
                                    class="whitespace-nowrap px-4 py-2 text-right tabular-nums"
                                    :class="t.amount_nanos > 0 ? 'text-emerald-700 dark:text-emerald-400' : ''"
                                    :title="formatExact(t.amount_nanos)"
                                >
                                    {{ formatMoney(t.amount_nanos, { signed: true }) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-muted-foreground">
                                    {{ formatBalance(t.balance_after_nanos) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <nav v-if="transactions.last_page > 1" class="flex items-center justify-between text-sm">
                    <Link v-if="transactions.prev_page_url" :href="transactions.prev_page_url" preserve-scroll class="hover:underline">← Newer</Link>
                    <span v-else />
                    <span class="text-muted-foreground">Page {{ transactions.current_page }} of {{ transactions.last_page }}</span>
                    <Link v-if="transactions.next_page_url" :href="transactions.next_page_url" preserve-scroll class="hover:underline">Older →</Link>
                    <span v-else />
                </nav>
            </section>
        </div>
    </AppLayout>
</template>
