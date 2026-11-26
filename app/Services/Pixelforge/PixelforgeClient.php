<?php

namespace App\Services\Pixelforge;

use App\Services\Pricing\PricingTable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {}

    /**
     * POST /v1/generations. The idempotency key makes retries safe: replaying it returns
     * the same generation instead of creating (and billing) a second one.
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images: list<string>, metadata: array<string, string>}  $params
     * @return array<string, mixed> generation object
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn () => $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $params));
    }

    /** @return array<string, mixed> generation object */
    public function getGeneration(string $id): array
    {
        return $this->send(fn () => $this->request()->get('generations/'.rawurlencode($id)));
    }

    /**
     * GET /v1/usage: what Pixelforge billed this API key, per UTC completion day.
     *
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn () => $this->request()->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /** GET /v1/pricing (public). Cached briefly; each generation still records its own rates. */
    public function pricing(): PricingTable
    {
        $body = Cache::remember('pixelforge.pricing', now()->addMinutes(10), fn () => $this->send(
            fn () => Http::baseUrl($this->baseUrl)->acceptJson()->timeout(10)->get('pricing')
        ));

        return PricingTable::fromApi($body);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    /**
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new PixelforgeException('connection_error', 'Could not reach Pixelforge: '.$e->getMessage(), previous: $e);
        }

        if ($response->successful()) {
            return $response->json();
        }

        throw $this->toException($response);
    }

    private function toException(Response $response): PixelforgeException
    {
        $error = $response->json('error') ?? [];
        $retryAfter = $response->header('Retry-After');

        return new PixelforgeException(
            errorCode: $error['code'] ?? ($response->serverError() ? 'server_error' : 'http_'.$response->status()),
            message: $error['message'] ?? 'Pixelforge returned HTTP '.$response->status(),
            httpStatus: $response->status(),
            retryAfterSeconds: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
