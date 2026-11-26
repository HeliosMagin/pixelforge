<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'prompt' => 'a lighthouse at dusk',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => GenerationStatus::Pending,
            'rates' => self::RATES,
            'estimated_nanos' => 40_000_000,
            'max_nanos' => 90_000_000,
        ];
    }

    public function accepted(string $pixelforgeId = 'gen_test123'): static
    {
        return $this->state(['pixelforge_id' => $pixelforgeId, 'status' => GenerationStatus::Queued]);
    }
}
