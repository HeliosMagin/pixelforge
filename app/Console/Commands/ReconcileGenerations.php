<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\Generations\GenerationStateMachine;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;

/**
 * Webhook delivery is not guaranteed, so poll Pixelforge for generations that have been
 * quiet for a while. Results go through the same state machine as webhooks.
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile {--stale=60 : Seconds without an update} {--limit=20}';

    protected $description = 'Poll Pixelforge for in-flight generations that have not heard from a webhook recently';

    public function handle(PixelforgeClient $pixelforge, GenerationStateMachine $states): int
    {
        $generations = Generation::query()
            ->active()
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', now()->subSeconds((int) $this->option('stale')))
            ->oldest('updated_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($generations as $generation) {
            try {
                $states->apply($generation, $pixelforge->getGeneration($generation->pixelforge_id));
                $generation->touch();
            } catch (PixelforgeException $e) {
                $this->warn("{$generation->pixelforge_id}: {$e->errorCode}");

                if ($e->isCapacityLimit()) {
                    break;
                }
            }
        }

        $this->info("Reconciled {$generations->count()} generation(s).");

        return self::SUCCESS;
    }
}
