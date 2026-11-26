<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stores each event once (keyed by its evt_ id) and answers immediately; the work happens
 * in a queued job so we stay well inside Pixelforge's 10-second delivery timeout.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $eventId = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;

        if (! is_string($eventId) || ! is_string($type)) {
            return response()->json(['error' => 'malformed_event'], 400);
        }

        if (! str_starts_with($type, 'generation.')) {
            return response()->json(['received' => true]); // e.g. "ping" from the dashboard
        }

        $inserted = WebhookEvent::query()->insertOrIgnore([
            'event_id' => $eventId,
            'type' => $type,
            'pixelforge_generation_id' => $payload['data']['object']['id'] ?? null,
            'payload' => json_encode($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted) {
            ProcessWebhookEvent::dispatch(WebhookEvent::where('event_id', $eventId)->value('id'));
        } else {
            WebhookEvent::where('event_id', $eventId)->increment('delivery_count');
        }

        return response()->json(['received' => true, 'duplicate' => ! $inserted]);
    }
}
