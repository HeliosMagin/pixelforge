<script setup lang="ts">
import { formatBalance, formatExact } from '@/lib/money';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Wallet } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<SharedData>();
const balance = computed(() => page.props.auth.user?.balance_nanos ?? 0);
</script>

<template>
    <Link
        :href="route('billing.index')"
        class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm font-medium tabular-nums hover:bg-accent"
        :title="`Balance: ${formatExact(balance)}`"
    >
        <Wallet class="size-4 text-muted-foreground" />
        {{ formatBalance(balance) }}
    </Link>
</template>
