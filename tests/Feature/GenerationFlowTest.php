<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Enums\TransactionType;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\Ledger;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class GenerationFlowTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    private const PAYLOAD = ['prompt' => 'a lighthouse at dusk', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => []];

    public function test_submitting_holds_the_max_price_and_sends_only_documented_fields(): void
    {
        $user = User::factory()->create();
        app(Ledger::class)->purchase($user, 5 * Money::NANOS_PER_DOLLAR);

        Http::fake([
            '*/pricing' => Http::response($this->pricingResponse()),
            '*/generations' => function (Request $request) {
                $generation = Generation::first();

                return Http::response($this->remoteGeneration($generation, 'queued', ['id' => 'gen_abc']), 202);
            },
        ]);

        $this->actingAs($user)->post('/generations', self::PAYLOAD)->assertRedirect('/generations');

        $generation = Generation::sole();
        $this->assertSame('gen_abc', $generation->pixelforge_id);
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame(5 * Money::NANOS_PER_DOLLAR - $generation->max_nanos, $user->fresh()->balance_nanos);

        Http::assertSent(function (Request $request) use ($generation) {
            return str_ends_with($request->url(), '/generations')
                && $request->header('Idempotency-Key')[0] === $generation->uuid
                && array_keys($request->data()) === ['prompt', 'size', 'quality', 'reference_images', 'metadata']
                && $request->data()['metadata'] === ['local_id' => $generation->uuid];
        });
    }

    public function test_submitting_is_blocked_when_the_user_cannot_afford_the_max_price(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricingResponse())]);
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', self::PAYLOAD)->assertSessionHasErrors('balance');

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();
    }

    public function test_estimate_endpoint(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricingResponse())]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/generations/estimate?'.http_build_query([...self::PAYLOAD, 'quality' => 'high', 'size' => '1536x1024', 'prompt' => str_repeat('a', 400), 'reference_images' => ['https://a.test/1.png', 'https://a.test/2.png']]))
            ->assertOk()
            ->assertJson(['max_nanos' => 467_343_750, 'affordable' => false, 'markup_percent' => 25]);
    }

    public function test_rate_limits_are_retried_with_the_same_idempotency_key(): void
    {
        $generation = $this->heldGeneration();
        $job = new SubmitGeneration($generation->id);
        $job->withFakeQueueInteractions();

        Http::fake(['*/generations' => Http::response(['error' => ['type' => 'rate_limit_error', 'code' => 'concurrency_limit_exceeded', 'message' => 'Too many']], 429, ['Retry-After' => '5'])]);

        app()->call([$job, 'handle']);

        $job->assertReleased();
        $generation->refresh();
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(SubmitGeneration::DETAIL_WAITING_FOR_CAPACITY, $generation->status_detail);
    }

    public function test_content_policy_rejection_fails_the_generation_for_free(): void
    {
        $generation = $this->heldGeneration(['prompt' => 'something [blocked]']);
        Http::fake(['*/generations' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Blocked']], 400)]);

        app()->call([new SubmitGeneration($generation->id), 'handle']);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('content_policy_violation', $generation->error_code);
        $this->assertSame(10 * Money::NANOS_PER_DOLLAR, $generation->user->fresh()->balance_nanos);
        $this->assertSame(1, $generation->transactions()->where('type', TransactionType::HoldRelease)->count());
    }

    public function test_job_does_nothing_when_a_webhook_already_moved_the_generation_on(): void
    {
        $generation = $this->heldGeneration(['pixelforge_id' => 'gen_test123', 'status' => GenerationStatus::Processing]);
        Http::fake();

        app()->call([new SubmitGeneration($generation->id), 'handle']);

        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::Processing, $generation->fresh()->status);
    }

    public function test_reconcile_command_finishes_generations_whose_webhooks_never_arrived(): void
    {
        $generation = $this->heldGeneration(['pixelforge_id' => 'gen_test123', 'status' => GenerationStatus::Processing]);
        Generation::whereKey($generation->id)->update(['updated_at' => now()->subMinutes(5)]);
        Http::fake(['*/generations/gen_test123' => Http::response($this->remoteGeneration($generation, 'succeeded'))]);

        $this->artisan('generations:reconcile')->assertSuccessful();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(70_687_500, $generation->charged_nanos);
    }

    public function test_buying_credits(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/billing/credits', ['amount' => 10])->assertRedirect();
        $this->actingAs($user)->post('/billing/credits', ['amount' => 1000])->assertSessionHasErrors('amount');

        $this->assertSame(10 * Money::NANOS_PER_DOLLAR, $user->fresh()->balance_nanos);
    }
}
