<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\InsufficientCredits;
use App\Services\Credits\Ledger;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pricing\PriceCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records a generation and reserves its worst-case price, then hands it to the queue to be
 * sent to Pixelforge. Nothing is sent unless the reservation succeeded.
 */
class CreateGeneration
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly PriceCalculator $calculator,
        private readonly Ledger $ledger,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images?: list<string>}  $input
     *
     * @throws InsufficientCredits
     */
    public function handle(User $user, array $input): Generation
    {
        $pricing = $this->pixelforge->pricing();
        $references = array_values($input['reference_images'] ?? []);
        $estimate = $this->calculator->estimate($pricing, $input['prompt'], $input['size'], $input['quality'], count($references));

        $generation = DB::transaction(function () use ($user, $input, $references, $pricing, $estimate) {
            $generation = $user->generations()->create([
                'uuid' => (string) Str::uuid(),
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $references,
                'status' => GenerationStatus::Pending,
                // Replaced by the generation's own rates once Pixelforge accepts it.
                'rates' => $pricing->rates,
                'estimated_nanos' => $estimate->expectedNanos,
                'max_nanos' => $estimate->maxNanos,
            ]);

            $this->ledger->hold($generation);

            return $generation;
        });

        SubmitGeneration::dispatch($generation->id);

        return $generation;
    }
}
