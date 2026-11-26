<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Services\Credits\InsufficientCredits;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pricing\PriceCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->latest()->paginate(12);

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
        ]);
    }

    public function create(Request $request): Response
    {
        // "Try again" prefills the form from an earlier generation.
        $from = $request->user()->generations()->where('uuid', $request->query('from'))->first();

        return Inertia::render('generations/Create', [
            'sizes' => Generation::SIZES,
            'qualities' => Generation::QUALITIES,
            'maxPromptLength' => Generation::MAX_PROMPT_LENGTH,
            'maxReferenceImages' => Generation::MAX_REFERENCE_IMAGES,
            'initial' => [
                'prompt' => $from->prompt ?? '',
                'size' => $from->size ?? '1024x1024',
                'quality' => $from->quality ?? 'medium',
                'reference_images' => $from->reference_images ?? [],
            ],
        ]);
    }

    public function estimate(GenerationRequest $request, PixelforgeClient $pixelforge, PriceCalculator $calculator): JsonResponse
    {
        try {
            $pricing = $pixelforge->pricing();
        } catch (PixelforgeException) {
            return response()->json(['message' => "Couldn't load Pixelforge's prices. Please try again in a moment."], 503);
        }

        $estimate = $calculator->estimate(
            $pricing,
            (string) $request->validated('prompt'),
            $request->validated('size'),
            $request->validated('quality'),
            count($request->validated('reference_images', [])),
        );

        return response()->json([
            ...$estimate->toArray(),
            'rates' => $pricing->rates,
            'balance_nanos' => $request->user()->balance_nanos,
            'affordable' => $request->user()->balance_nanos >= $estimate->maxNanos,
            'markup_percent' => (int) config('services.pixelforge.markup_percent'),
        ]);
    }

    public function store(GenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        try {
            $createGeneration->handle($request->user(), $request->validated());
        } catch (InsufficientCredits) {
            throw ValidationException::withMessages([
                'balance' => 'Your balance is too low for the maximum this generation could cost. Buy credits to continue.',
            ]);
        } catch (PixelforgeException) {
            throw ValidationException::withMessages([
                'prompt' => "Couldn't load Pixelforge's prices, so nothing was submitted. Please try again in a moment.",
            ]);
        }

        return to_route('generations.index')->with('success', 'Generation queued. It usually takes 5–30 seconds.');
    }
}
