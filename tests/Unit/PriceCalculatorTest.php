<?php

namespace Tests\Unit;

use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\PricingTable;
use App\Support\Money;
use Database\Factories\GenerationFactory;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\FakesPixelforge;

class PriceCalculatorTest extends TestCase
{
    use FakesPixelforge;

    private PriceCalculator $calculator;

    private PricingTable $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PriceCalculator(markupPercent: 25);
        $this->pricing = PricingTable::fromApi($this->pricingResponse());
    }

    public function test_worst_case_matches_the_pixelforge_docs_example(): void
    {
        // Docs: high, 1536x1024, 400-character prompt, 2 reference images => max $0.373875.
        $estimate = $this->calculator->estimate($this->pricing, str_repeat('a', 400), '1536x1024', 'high', 2);

        $this->assertSame(115, $estimate->tokens['input_text_tokens']['max']);
        $this->assertSame(1530, $estimate->tokens['input_image_tokens']['max']);
        $this->assertSame(373_875_000, $estimate->providerMaxNanos);
        $this->assertSame(Money::withMarkup(373_875_000, 25), $estimate->maxNanos);
        $this->assertSame(467_343_750, $estimate->maxNanos);
    }

    public function test_expected_uses_estimated_text_and_midpoint_reasoning(): void
    {
        $estimate = $this->calculator->estimate($this->pricing, str_repeat('a', 40), '1024x1024', 'medium', 0);

        // 10 text, 1100 output, 1100 reasoning (midpoint of 200–2000).
        $provider = 10 * 5_000 + 1100 * 40_000 + 1100 * 12_500;
        $this->assertSame($provider, $estimate->providerExpectedNanos);
        $this->assertSame((int) ($provider * 1.25), $estimate->expectedNanos);
        $this->assertLessThanOrEqual($estimate->expectedNanos, $estimate->minNanos);
        $this->assertGreaterThanOrEqual($estimate->expectedNanos, $estimate->maxNanos);
    }

    public function test_text_tokens_count_unicode_characters_not_bytes(): void
    {
        $estimate = $this->calculator->estimate($this->pricing, str_repeat('é', 8), '1024x1024', 'low', 0);

        $this->assertSame(2, $estimate->tokens['input_text_tokens']['expected']);
    }

    public function test_actual_cost_uses_the_generations_own_rates(): void
    {
        $usage = ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000];

        $this->assertSame(
            ['provider_nanos' => 56_550_000, 'charged_nanos' => 70_687_500],
            $this->calculator->actual($usage, GenerationFactory::RATES),
        );

        $doubled = array_map(fn ($rate) => bcmul($rate, '2', 2), GenerationFactory::RATES);
        $this->assertSame(113_100_000, $this->calculator->actual($usage, $doubled)['provider_nanos']);
    }

    public function test_markup_rounds_up_to_the_next_nano(): void
    {
        $this->assertSame(2, Money::withMarkup(1, 25));
        $this->assertSame(125, Money::withMarkup(100, 25));
        $this->assertSame(0, Money::withMarkup(0, 25));
    }

    public function test_decimal_conversion_is_exact(): void
    {
        $this->assertSame(169_080_000, Money::decimalToNanos('0.169080000'));
        $this->assertSame(12_500_000_000, Money::decimalToNanos('12.50'));
        $this->assertSame('0.169080000', Money::nanosToDecimal(169_080_000));
        $this->assertSame('-1.000000001', Money::nanosToDecimal(-1_000_000_001));
    }
}
