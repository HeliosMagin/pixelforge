<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generations\GenerationStateMachine;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a pending generation to Pixelforge, waiting out rate, concurrency and overload
 * limits. Every attempt reuses the generation's uuid as the Idempotency-Key, so a retry
 * after a timeout can never create or bill a second generation.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public const DETAIL_WAITING_FOR_CAPACITY = 'waiting_for_capacity';

    public const DETAIL_RETRYING = 'retrying';

    /** Unlimited releases until retryUntil(); unexpected exceptions get a few attempts. */
    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(public readonly int $generationId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(20);
    }

    public function handle(PixelforgeClient $pixelforge, GenerationStateMachine $states): void
    {
        $generation = Generation::find($this->generationId);

        // Already accepted (a webhook can beat the POST response) or already failed.
        if (! $generation || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        if ($this->atConcurrencyLimit()) {
            $states->noteDetail($generation, self::DETAIL_WAITING_FOR_CAPACITY);
            $this->release($this->withJitter(5));

            return;
        }

        try {
            $remote = $pixelforge->createGeneration($generation->toPixelforgeRequest(), $generation->uuid);
        } catch (PixelforgeException $e) {
            if (! $e->isRetryable()) {
                $states->failLocally($generation, $e->errorCode, $e->getMessage());

                return;
            }

            $states->noteDetail($generation, $e->isCapacityLimit() ? self::DETAIL_WAITING_FOR_CAPACITY : self::DETAIL_RETRYING);
            $this->release($this->withJitter($e->retryAfterSeconds ?? 5));

            return;
        }

        $states->apply($generation, $remote);
    }

    /** Called when we run out of time or attempts. */
    public function failed(?Throwable $e): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation && $generation->status === GenerationStatus::Pending) {
            app(GenerationStateMachine::class)->failLocally(
                $generation,
                'submission_timeout',
                'Pixelforge was too busy to accept this generation. Please try again.',
            );
        }
    }

    /**
     * Pixelforge allows a few generations in flight per account. Waiting locally saves
     * requests that would only come back 429; the API's own 429 is still handled above.
     */
    private function atConcurrencyLimit(): bool
    {
        return Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->count() >= config('services.pixelforge.max_concurrent_generations');
    }

    private function withJitter(int $seconds): int
    {
        return max(1, $seconds) + random_int(0, 2);
    }
}
