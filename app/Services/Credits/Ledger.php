<?php

namespace App\Services\Credits;

use App\Enums\TransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Append-only record of every credit movement. users.balance_nanos is a cached running
 * total, updated in the same transaction (with the user row locked) as each entry.
 *
 * Lifecycle of a generation's money:
 *   submit    -> hold (-max price)
 *   succeeded -> hold_release (+max) and charge (-actual)
 *   failed    -> hold_release (+max)
 *
 * A generation can have at most one entry of each type (unique index), so no matter how
 * often an outcome is reported it can only be recorded once.
 */
class Ledger
{
    public function purchase(User $user, int $nanos): CreditTransaction
    {
        return DB::transaction(function () use ($user, $nanos) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $transaction = $this->record($locked, TransactionType::Purchase, $nanos, null, 'Bought credits');
            $user->balance_nanos = $locked->balance_nanos;

            return $transaction;
        });
    }

    /** Reserve the worst-case price. Must be called inside the transaction that creates the generation. */
    public function hold(Generation $generation): void
    {
        DB::transaction(function () use ($generation) {
            $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);

            if ($user->balance_nanos < $generation->max_nanos) {
                throw new InsufficientCredits($generation->max_nanos, $user->balance_nanos);
            }

            $this->record($user, TransactionType::Hold, -$generation->max_nanos, $generation, 'Reserved for generation');
        });
    }

    /** Swap the hold for the actual charge. */
    public function settle(Generation $generation): void
    {
        DB::transaction(function () use ($generation) {
            $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);
            $this->releaseHold($user, $generation);

            if (! $this->exists($generation, TransactionType::Charge)) {
                $this->record($user, TransactionType::Charge, -$generation->charged_nanos, $generation, 'Generation');
            }
        });
    }

    /** Return the hold in full: failed generations are free. */
    public function release(Generation $generation): void
    {
        DB::transaction(function () use ($generation) {
            $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);
            $this->releaseHold($user, $generation);
        });
    }

    private function releaseHold(User $user, Generation $generation): void
    {
        $hold = $generation->transactions()->where('type', TransactionType::Hold)->first();

        if ($hold && ! $this->exists($generation, TransactionType::HoldRelease)) {
            $this->record($user, TransactionType::HoldRelease, -$hold->amount_nanos, $generation, 'Reservation released');
        }
    }

    private function exists(Generation $generation, TransactionType $type): bool
    {
        return $generation->transactions()->where('type', $type)->exists();
    }

    /** Caller must hold a lock on the user row. */
    private function record(User $user, TransactionType $type, int $amount, ?Generation $generation, string $description): CreditTransaction
    {
        $user->balance_nanos += $amount;
        $user->save();

        return CreditTransaction::create([
            'user_id' => $user->id,
            'generation_id' => $generation?->id,
            'type' => $type,
            'amount_nanos' => $amount,
            'balance_after_nanos' => $user->balance_nanos,
            'description' => $description,
        ]);
    }
}
