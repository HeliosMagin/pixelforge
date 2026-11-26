<?php

namespace App\Services\Pricing;

/**
 * Up-front price for a generation. Only reasoning tokens and the exact text token
 * count are unknown before it finishes, so the real price always lands in [min, max].
 */
final readonly class Estimate
{
    /**
     * @param  array<string, array{expected: int, min: int, max: int}>  $tokens  per token kind
     */
    public function __construct(
        public array $tokens,
        public int $providerExpectedNanos,
        public int $providerMaxNanos,
        public int $expectedNanos,
        public int $minNanos,
        public int $maxNanos,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tokens' => $this->tokens,
            'expected_nanos' => $this->expectedNanos,
            'min_nanos' => $this->minNanos,
            'max_nanos' => $this->maxNanos,
        ];
    }
}
