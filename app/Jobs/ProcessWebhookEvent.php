<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Generations\GenerationStateMachine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [5, 15, 60];

    public function __construct(public readonly int $webhookEventId) {}

    public function handle(GenerationStateMachine $states): void
    {
        $event = WebhookEvent::findOrFail($this->webhookEventId);

        if ($event->processed_at) {
            return;
        }

        $remote = $event->payload['data']['object'] ?? null;
        $generation = $remote ? $this->findGeneration($remote) : null;

        if ($generation) {
            $states->apply($generation, $remote);
        } else {
            Log::warning('Pixelforge webhook for an unknown generation', ['event_id' => $event->event_id]);
        }

        $event->update(['processed_at' => now()]);
    }

    /**
     * Match by Pixelforge id, or by our own id echoed in metadata: a webhook can arrive
     * before the POST response that tells us the Pixelforge id.
     *
     * @param  array<string, mixed>  $remote
     */
    private function findGeneration(array $remote): ?Generation
    {
        $localId = $remote['metadata']['local_id'] ?? null;

        return Generation::query()
            ->where('pixelforge_id', $remote['id'] ?? null)
            ->when(is_string($localId) && Str::isUuid($localId), fn ($query) => $query->orWhere('uuid', $localId))
            ->first();
    }
}
