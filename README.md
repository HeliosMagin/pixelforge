# Pixelforge Studio

A Laravel 12 + Inertia + Vue 3 app where signed-in users generate images through the [Pixelforge API](https://pixelforge.promptedit.dev/docs). Users pay Pixelforge's cost plus 25%. They see a price estimate before they submit, and the actual cost is charged once the image is done.

## Running locally (Laravel Sail)

```bash
cp .env.example .env            # set PIXELFORGE_API_KEY / PIXELFORGE_WEBHOOK_SECRET (use a local-only key)
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate && ./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install && ./vendor/bin/sail npm run dev
./vendor/bin/sail artisan queue:work     # submits generations, processes webhooks
./vendor/bin/sail artisan schedule:work  # polls Pixelforge if a webhook goes missing
./vendor/bin/sail artisan app:make-admin you@example.com   # access to /admin/report
```

For webhooks to reach your machine, open a tunnel such as `sail share`, ngrok or cloudflared. In the Pixelforge dashboard, set the key's webhook URL to `https://<tunnel>/webhooks/pixelforge`.

Run the tests with `./vendor/bin/sail artisan test`. To run a single test, use `./vendor/bin/sail artisan test --filter=WebhookTest`.

## How it works

```
Generate page ──GET /generations/estimate──► PriceCalculator (expected + max)
      │
      └─POST /generations ─► CreateGeneration: hold max price (ledger) + save `pending` row
                                   │
                                   ▼
                     SubmitGeneration job ──POST /v1/generations (Idempotency-Key = our uuid,
                       │   429/503 → release(Retry-After)        metadata.local_id = our uuid)
                       ▼
 webhook ─► verify signature ─► store event (unique evt_ id) ─► ProcessWebhookEvent ─┐
 scheduler ─► generations:reconcile (GET /v1/generations/{id}) ─────────────────────┤
 POST response ─────────────────────────────────────────────────────────────────────┤
                                                                                    ▼
                                                        GenerationStateMachine::apply()
                                         forward-only status; on success: cost from usage × the
                                         generation's own rates → release hold + charge (once);
                                                          on failure: release hold (free)
```

## Decisions

- **Money is stored as integer nano-dollars (1e-9 USD).** Pixelforge reports costs with 9 decimal places, and its rates have 2 decimals per 1M tokens. That means every token costs a whole number of nanos, so provider cost is exact and its daily totals match `GET /v1/usage` to the nano. Floats and rounding never come into it.
- **Markup is rounded up to the next nano.** With the current rates the result is always exact. The rounding rule only matters if the rates ever get more decimal places.
- **The actual cost uses the generation's own `rates`.** Pixelforge fixes the rates when a generation is created, so a price change while it's in flight can't make the charge wrong.
- **The estimate is a range.** Before submitting, the only unknowns are the reasoning tokens (bounded by quality) and the exact text token count (±15%). The UI shows an expected price (estimated text tokens, midpoint reasoning) and a guaranteed maximum. Users are told they pay the actual price, never more than the maximum.
- **Holds.** Submitting reserves the maximum price, and generating is blocked if the balance can't cover it. When the generation finishes, the hold is returned and the actual price is charged. A failure returns the hold, so it's free. The ledger is append-only, and `users.balance_nanos` is a cached total updated in the same transaction with the user row locked.
- **Never recorded twice.** There are three independent guards:
  1. Webhook events are deduplicated on their unique `evt_` id.
  2. The state machine locks the generation row and only moves status forward, so the terminal transition happens once.
  3. The ledger has a unique `(generation_id, type)` index.
- **Out-of-order events.** The statuses are ordered `pending < queued < processing < succeeded|failed`, and anything that isn't a forward move is ignored. A webhook that arrives before the POST response is matched by `metadata.local_id`.
- **Rate, concurrency and overload limits.** Generations are queued locally, so a burst of submissions waits in our queue instead of failing. The submit job checks a local gate for Pixelforge's limit of 3 generations in flight, and honours `Retry-After` on 429 and 503. Every retry reuses the same `Idempotency-Key`, so a timed-out request can never create or bill a duplicate. While a generation waits, the UI shows "waiting for Pixelforge capacity".
- **Missed webhooks.** Delivery isn't guaranteed, so a scheduled job polls generations that have been quiet for 60 seconds.
- **Billing day is the UTC completion day.** That's how Pixelforge bills. The admin report uses the same buckets and shows the difference against `/v1/usage` for each day.
- **Live status uses Inertia polling.** The History page polls every 2 seconds, and only while something on the page is still in flight. It needs no websocket infrastructure, and status changes every few seconds at most.
- **Images aren't stored.** The brief allows image links to expire after 24 hours, and the UI shows a placeholder when they do.

### Known edge cases

- If the submit job gives up after 20 minutes with no response from Pixelforge, the generation is marked failed and the hold is returned. If Pixelforge actually accepted it and later finishes it, we would be billed without charging the user. That would show up as a difference in the admin report.
- If Pixelforge raised its rates between our price estimate and its acceptance of a generation, the actual charge could exceed the hold. It is still charged correctly, so a balance could go slightly negative.
