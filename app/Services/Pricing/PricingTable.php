<?php

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * Pixelforge's published rates and token rules (GET /v1/pricing), in integer units.
 */
final readonly class PricingTable
{
    public const TOKEN_KINDS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * @param  array<string, string>  $rates  USD per 1M tokens as decimal strings, keyed by token kind
     * @param  array<string, array<string, int>>  $outputTokens  [quality][size] => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  [quality] => range
     */
    public function __construct(
        public array $rates,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBasisPoints,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /** @param  array<string, mixed>  $response  body of GET /v1/pricing */
    public static function fromApi(array $response): self
    {
        $tokens = $response['tokens'];

        return new self(
            rates: array_map('strval', $response['rates']),
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            textTokenVarianceBasisPoints: (int) round(((float) $tokens['text_token_variance']) * 10_000),
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: array_map(fn ($sizes) => array_map('intval', $sizes), $tokens['output']),
            reasoningTokens: array_map(fn ($r) => ['min' => (int) $r['min'], 'max' => (int) $r['max']], $tokens['reasoning']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates,
            'characters_per_text_token' => $this->charactersPerTextToken,
            'text_token_variance_basis_points' => $this->textTokenVarianceBasisPoints,
            'tokens_per_reference_image' => $this->tokensPerReferenceImage,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }

    /** @return list<string> */
    public function sizes(): array
    {
        return array_keys(reset($this->outputTokens) ?: []);
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->outputTokens);
    }

    /**
     * Exact provider cost for a set of token counts, using the given per-1M-token rates.
     *
     * @param  array<string, int>  $tokens
     * @param  array<string, string>  $rates
     */
    public static function costNanos(array $tokens, array $rates): int
    {
        // Σ tokens × (nanos per 1M tokens), then ÷ 1M. With Pixelforge's 2-decimal rates the division
        // is always exact; ceilDiv only matters if rates ever gain more precision.
        $nanosTimesMillion = 0;
        foreach (self::TOKEN_KINDS as $kind) {
            $nanosTimesMillion += ($tokens[$kind] ?? 0) * Money::decimalToNanos($rates[$kind]);
        }

        return Money::ceilDiv($nanosTimesMillion, 1_000_000);
    }
}
