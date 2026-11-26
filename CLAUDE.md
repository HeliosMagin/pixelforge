# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

Everything runs through Laravel Sail. The host's PHP and Node are too old. The app is served at http://localhost:8090 and the Vite dev server runs on port 5183.

- `./vendor/bin/sail up -d`: start the app and Postgres
- `./vendor/bin/sail artisan test`: run all tests (PHPUnit, not Pest). For a single test, use `--filter=WebhookTest` or `--filter=test_failure_is_free`.
- `./vendor/bin/sail pint`: PHP code style. `./vendor/bin/sail npx prettier --write resources/js`: JS/Vue style.
- `./vendor/bin/sail npm run dev` / `npm run build`: frontend. Run `./vendor/bin/sail npx vue-tsc --noEmit` to type-check. It reports errors that are already in the starter kit's own files. Only errors in files you touched matter.
- `./vendor/bin/sail artisan queue:work` and `schedule:work`: both are required for generations to progress
- `./vendor/bin/sail artisan generations:reconcile`: poll Pixelforge for in-flight generations
- `./vendor/bin/sail artisan app:make-admin <email>`

## Architecture

This is a Pixelforge image-generation client. The README "Decisions" section explains the reasoning; read it before changing money or state logic.

- **All money is integer nano-dollars** (`*_nanos` columns, `App\Support\Money`). Never use floats for amounts. The frontend formats them in `resources/js/lib/money.ts`.
- **`GenerationStateMachine::apply()` is the only writer of a generation's remote state.** Webhooks (`ProcessWebhookEvent`), the POST response (`SubmitGeneration`) and polling (`ReconcileGenerations`) all go through it. Status only moves forward (`GenerationStatus::rank()`), and the ledger is settled inside the same locked transaction.
- **`Ledger`** is the only writer of `credit_transactions` and `users.balance_nanos`. The lifecycle is hold (max price) → hold_release plus charge (success) or hold_release only (failure). A unique `(generation_id, type)` index enforces "recorded once".
- **`PriceCalculator`** is the single source of truth for estimates and actual cost. The frontend fetches the estimate from `GET /generations/estimate` rather than duplicating the math.
- **`PixelforgeClient`** maps the API's `error.code` to `PixelforgeException`, which provides `isRetryable()` and `isCapacityLimit()`. Pixelforge rejects unknown request fields, so send only what `Generation::toPixelforgeRequest()` builds.
- The webhook route `/webhooks/pixelforge` is CSRF-exempt (`bootstrap/app.php`) and protected by `VerifyPixelforgeSignature` (HMAC of `"<t>.<raw body>"`).
- Inertia pages live in `resources/js/pages/` (lowercase). `config/inertia.php` points the test page finder there.

## Pixelforge testing

Prompt keywords force specific behaviour: `[succeed]`, `[fail]`, `[slow]`, `[duplicate]` (webhooks delivered twice), `[blocked]` (400 content policy), `[overloaded]` (503). Use a separate API key per environment. `/v1/usage` reports per key.
