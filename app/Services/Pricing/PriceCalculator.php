<?php

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * The single source of truth for what a generation costs: the estimate shown before
 * submitting, and the actual price recorded once Pixelforge reports token usage.
 */
final class PriceCalculator
{
    public function __construct(private readonly int $markupPercent) {}

    public function estimate(PricingTable $pricing, string $prompt, string $size, string $quality, int $referenceImageCount): Estimate
    {
        $textEstimate = (int) ceil(mb_strlen($prompt) / $pricing->charactersPerTextToken);
        $variance = $pricing->textTokenVarianceBasisPoints;
        $reasoning = $pricing->reasoningTokens[$quality];
        $output = $pricing->outputTokens[$quality][$size];
        $images = $referenceImageCount * $pricing->tokensPerReferenceImage;

        $tokens = [
            'input_text_tokens' => [
                'expected' => $textEstimate,
                'min' => intdiv($textEstimate * (10_000 - $variance), 10_000),
                'max' => Money::ceilDiv($textEstimate * (10_000 + $variance), 10_000),
            ],
            'input_image_tokens' => ['expected' => $images, 'min' => $images, 'max' => $images],
            'output_tokens' => ['expected' => $output, 'min' => $output, 'max' => $output],
            'reasoning_tokens' => [
                'expected' => intdiv($reasoning['min'] + $reasoning['max'], 2),
                'min' => $reasoning['min'],
                'max' => $reasoning['max'],
            ],
        ];

        $provider = fn (string $bound) => PricingTable::costNanos(array_map(fn ($t) => $t[$bound], $tokens), $pricing->rates);

        return new Estimate(
            tokens: $tokens,
            providerExpectedNanos: $provider('expected'),
            providerMaxNanos: $provider('max'),
            expectedNanos: $this->charge($provider('expected')),
            minNanos: $this->charge($provider('min')),
            maxNanos: $this->charge($provider('max')),
        );
    }

    /**
     * Actual cost of a finished generation.
     *
     * @param  array<string, int>  $usage  the generation's `usage` object
     * @param  array<string, string>  $rates  the generation's own `rates` (fixed by Pixelforge at creation)
     * @return array{provider_nanos: int, charged_nanos: int}
     */
    public function actual(array $usage, array $rates): array
    {
        $provider = PricingTable::costNanos($usage, $rates);

        return ['provider_nanos' => $provider, 'charged_nanos' => $this->charge($provider)];
    }

    /** What the user pays for a given provider cost. */
    public function charge(int $providerNanos): int
    {
        return Money::withMarkup($providerNanos, $this->markupPercent);
    }
}
