<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Enums\TransactionType;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesPixelforge;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use FakesPixelforge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    public function test_it_rejects_bad_signatures(): void
    {
        $generation = $this->heldGeneration(['pixelforge_id' => 'gen_test123', 'status' => GenerationStatus::Queued]);
        $event = $this->event('evt_1', 'generation.succeeded', $this->remoteGeneration($generation, 'succeeded'));

        $this->postWebhook($event, secret: 'whsec_wrong')->assertStatus(400);
        $this->postWebhook($event, timestamp: time() - 301)->assertStatus(400);

        $this->call('POST', '/webhooks/pixelforge', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event))
            ->assertStatus(400);

        $body = json_encode($event);
        $t = time();
        $signature = hash_hmac('sha256', "{$t}.{$body}", self::WEBHOOK_SECRET);
        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$t},v1={$signature}",
        ], str_replace('succeeded', 'failed', $body))->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(GenerationStatus::Queued, $generation->fresh()->status);
    }

    public function test_success_records_the_exact_cost_once(): void
    {
        $generation = $this->heldGeneration(['pixelforge_id' => 'gen_test123', 'status' => GenerationStatus::Queued]);
        $event = $this->event('evt_1', 'generation.succeeded', $this->remoteGeneration($generation, 'succeeded'));

        $this->postWebhook($event)->assertOk()->assertJson(['duplicate' => false]);
        $this->postWebhook($event)->assertOk()->assertJson(['duplicate' => true]);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(56_550_000, $generation->provider_cost_nanos);
        $this->assertSame(70_687_500, $generation->charged_nanos);
        $this->assertSame(1, $generation->transactions()->where('type', TransactionType::Charge)->count());
        $this->assertSame(10_000_000_000 - 70_687_500, $generation->user->fresh()->balance_nanos);
        $this->assertSame(2, WebhookEvent::first()->delivery_count);
    }

    public function test_a_different_event_for_the_same_outcome_is_not_charged_twice(): void
    {
        $generation = $this->heldGeneration(['pixelforge_id' => 'gen_test123', 'status' => GenerationStatus::Queued]);
        $remote = $this->remoteGeneration($generation, 'succeeded');

        $this->postWebhook($this->event('evt_1', 'generation.succeeded', $remote))->assertOk();
        $this->postWebhook($this->event('evt_2', 'generation.succeeded', $remote))->assertOk();

        $this->assertSame(1, $generation->transactions()->where('type', TransactionType::Charge)->count());
        $this->assertSame(10_000_000_000 - 70_687_500, $generation->user->fresh()->balance_nanos);
    }

    public function test_late_processing_event_does_not_move_status_backwards(): void
    {
        $generation = $this->heldGeneration(['pixelforge_id' => 'gen_test123', 'status' => GenerationStatus::Queued]);

        $this->postWebhook($this->event('evt_2', 'generation.succeeded', $this->remoteGeneration($generation, 'succeeded')));
        $this->postWebhook($this->event('evt_1', 'generation.processing', $this->remoteGeneration($generation, 'processing')));

        $this->assertSame(GenerationStatus::Succeeded, $generation->fresh()->status);
    }

    public function test_webhook_that_beats_the_post_response_is_matched_by_local_id(): void
    {
        // Still pending: we haven't seen the POST response, so we don't know the Pixelforge id yet.
        $generation = $this->heldGeneration();

        $this->postWebhook($this->event('evt_1', 'generation.processing', $this->remoteGeneration($generation, 'processing', ['id' => 'gen_early'])))
            ->assertOk();

        $generation->refresh();
        $this->assertSame('gen_early', $generation->pixelforge_id);
        $this->assertSame(GenerationStatus::Processing, $generation->status);
    }

    public function test_failure_is_free(): void
    {
        $generation = $this->heldGeneration(['pixelforge_id' => 'gen_test123', 'status' => GenerationStatus::Processing]);

        $this->postWebhook($this->event('evt_1', 'generation.failed', $this->remoteGeneration($generation, 'failed')))->assertOk();
        $this->postWebhook($this->event('evt_2', 'generation.succeeded', $this->remoteGeneration($generation, 'succeeded')))->assertOk();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame('generation_failed', $generation->error_code);
        $this->assertNull($generation->charged_nanos);
        $this->assertSame(0, $generation->transactions()->where('type', TransactionType::Charge)->count());
        $this->assertSame(10_000_000_000, $generation->user->fresh()->balance_nanos);
    }

    public function test_ping_is_acknowledged(): void
    {
        $this->postWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'data' => []])->assertOk();
        $this->assertSame(0, WebhookEvent::count());
    }
}
