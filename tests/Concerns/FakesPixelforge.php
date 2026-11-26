<?php

namespace Tests\Concerns;

use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\Ledger;
use App\Support\Money;
use Database\Factories\GenerationFactory;

trait FakesPixelforge
{
    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function pricingResponse(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => GenerationFactory::RATES,
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    /** A Pixelforge generation object for one of our generations. */
    protected function remoteGeneration(Generation $generation, string $status, array $overrides = []): array
    {
        $base = [
            'id' => $generation->pixelforge_id ?? 'gen_test123',
            'object' => 'generation',
            'status' => $status,
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images,
            'metadata' => ['local_id' => $generation->uuid],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => GenerationFactory::RATES,
            'error' => null,
            'created_at' => '2026-11-26T10:00:00Z',
            'started_at' => in_array($status, ['processing', 'succeeded', 'failed']) ? '2026-11-26T10:00:03Z' : null,
            'completed_at' => in_array($status, ['succeeded', 'failed']) ? '2026-11-26T10:00:20Z' : null,
        ];

        if ($status === 'succeeded') {
            $base['image_url'] = 'https://pixelforge.promptedit.dev/images/gen_test123.png?sig=abc';
            $base['image_url_expires_at'] = '2026-11-27T10:00:20Z';
            // 10 text + 1100 output + 1000 reasoning:
            // 10×5 + 1100×40 + 1000×12.5 = 56,550 µ$ per 1M => $0.056550 provider, $0.0706875 charged.
            $base['usage'] = [
                'input_text_tokens' => 10,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 1000,
                'total_tokens' => 2110,
            ];
        }

        if ($status === 'failed') {
            $base['error'] = ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'];
        }

        return array_replace($base, $overrides);
    }

    protected function event(string $id, string $type, array $generation): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-26T10:00:20Z',
            'data' => ['object' => $generation],
        ];
    }

    protected function postWebhook(array $event, ?string $secret = null, ?int $timestamp = null)
    {
        $body = json_encode($event);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? self::WEBHOOK_SECRET);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
        ], $body);
    }

    /** A user with $10 and a pending generation whose hold is in place. */
    protected function heldGeneration(array $attributes = []): Generation
    {
        $user = User::factory()->create();
        app(Ledger::class)->purchase($user, 10 * Money::NANOS_PER_DOLLAR);

        $generation = Generation::factory()->for($user)->create($attributes);
        app(Ledger::class)->hold($generation);

        return $generation->fresh();
    }
}
