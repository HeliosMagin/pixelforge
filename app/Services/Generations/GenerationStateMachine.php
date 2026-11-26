<?php

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Credits\Ledger;
use App\Services\Pricing\PriceCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only code that changes a generation's status. Every source of truth (the POST
 * response, webhooks, polling) funnels through here, so they all follow the same rules:
 *
 *  - status only moves forward (see GenerationStatus::rank), so an event that arrives late
 *    or twice is a no-op and a finished generation never changes again;
 *  - the money is settled in the same transaction as the move to a terminal status, which
 *    happens exactly once because the generation row is locked while we decide.
 */
class GenerationStateMachine
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly PriceCalculator $calculator,
    ) {}

    /**
     * Apply a Pixelforge generation object.
     *
     * @param  array<string, mixed>  $remote
     */
    public function apply(Generation $generation, array $remote): Generation
    {
        return DB::transaction(function () use ($generation, $remote) {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            $next = GenerationStatus::from($remote['status']);

            $locked->pixelforge_id ??= $remote['id'];

            if ($locked->status->canTransitionTo($next)) {
                $this->transition($locked, $next, $remote);
            }

            $locked->save();

            return $locked;
        });
    }

    /** Fail a generation that Pixelforge never accepted (e.g. content policy, or we gave up retrying). */
    public function failLocally(Generation $generation, string $errorCode, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $errorCode, $message) {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($locked->status->canTransitionTo(GenerationStatus::Failed)) {
                $locked->fill([
                    'status' => GenerationStatus::Failed,
                    'status_detail' => null,
                    'error_code' => $errorCode,
                    'error_message' => $message,
                    'completed_at' => now(),
                ])->save();

                $this->ledger->release($locked);
            }

            return $locked;
        });
    }

    /** Record a non-status detail, such as "waiting for capacity", on a generation that is still pending. */
    public function noteDetail(Generation $generation, ?string $detail): void
    {
        Generation::query()
            ->whereKey($generation->id)
            ->where('status', GenerationStatus::Pending)
            ->update(['status_detail' => $detail, 'updated_at' => now()]);
    }

    /** @param  array<string, mixed>  $remote */
    private function transition(Generation $generation, GenerationStatus $next, array $remote): void
    {
        $generation->fill([
            'status' => $next,
            'status_detail' => null,
            'rates' => $remote['rates'] ?? $generation->rates,
            'submitted_at' => $generation->submitted_at ?? $this->time($remote['created_at'] ?? null),
            'started_at' => $this->time($remote['started_at'] ?? null) ?? $generation->started_at,
            'completed_at' => $this->time($remote['completed_at'] ?? null),
        ]);

        if ($next === GenerationStatus::Succeeded) {
            $cost = $this->calculator->actual($remote['usage'], $generation->rates);

            $generation->fill([
                'usage' => $remote['usage'],
                'image_url' => $remote['image_url'],
                'image_url_expires_at' => $this->time($remote['image_url_expires_at'] ?? null),
                'provider_cost_nanos' => $cost['provider_nanos'],
                'charged_nanos' => $cost['charged_nanos'],
            ])->save();

            $this->ledger->settle($generation);
        }

        if ($next === GenerationStatus::Failed) {
            $generation->fill([
                'error_code' => $remote['error']['code'] ?? 'generation_failed',
                'error_message' => $remote['error']['message'] ?? null,
                'completed_at' => $generation->completed_at ?? now(),
            ])->save();

            $this->ledger->release($generation);
        }
    }

    private function time(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->utc() : null;
    }
}
