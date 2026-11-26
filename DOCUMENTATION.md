# Imagen — How the project works

Laravel 13 + Inertia + Vue 3 + Tailwind + PostgreSQL. Users sign in, generate images through **Pixelforge**, and are charged Pixelforge's cost plus a 25% markup. Credits are US dollars.

## 1. Running it locally

```sh
docker run -d --name pixelforge-pg -e POSTGRES_USER=app -e POSTGRES_PASSWORD=secret \
  -e POSTGRES_DB=pixelforge -p 5432:5432 postgres:17-alpine
cp .env.example .env && php artisan key:generate
# set PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET (whsec_...) in .env
php artisan migrate
npm install && npm run build      # or: npm run dev
php artisan serve
php artisan app:make-admin you@example.com   # grants the admin report
php artisan test
```

Pixelforge must be able to reach `POST /webhooks/pixelforge` on a public URL (use a tunnel locally). Set that URL and copy the `whsec_` secret from the Pixelforge dashboard for the API key you use.

## 2. Money model

All amounts are **integer nanodollars** (1 USD = 1,000,000,000). Pixelforge cost = `tokens × rate / 1M`. With rates quoted in cents this is always a whole number of nanodollars, so nothing is ever rounded and our numbers match Pixelforge's 9-decimal `cost_usd` exactly. `App\Services\Money` holds the helpers (rate → nano per token, markup, formatting).

The user price is `cost × 1.25` (`Money::withMarkup`, config `pixelforge.markup_percent`), rounded to the nearest nanodollar.

## 3. Pricing: estimate vs actual (`PricingService`)

| Component | Known up front? | How |
|---|---|---|
| Input text tokens | approx (±15%) | `ceil(chars / 4)` |
| Reference image tokens | exact | 765 per image |
| Output tokens | exact | table by quality × size (`config/pixelforge.php`) |
| Reasoning tokens | **no** | only a per-quality range |

- **Estimate** shown on the Generate page: the expected price (reasoning at range midpoint) plus a min–max range. The browser computes it live (`resources/js/money.js`, mirrors the PHP); the server recomputes it authoritatively on submit and stores it as `estimated_cost_nano`.
- **Worst case** (`estimated_max_nano`: +15% text, max reasoning) is what we check the balance against.
- **Actual** is computed when the generation succeeds, from the `usage` Pixelforge reports and the `rates` fixed at creation: `provider_cost_nano`, then `charged_nano = provider_cost × 1.25`.
- Failed generations: `charged_nano = 0`, `provider_cost_nano = 0`.
- Rates come from `GET /v1/pricing` (cached 10 min), falling back to config.

## 4. Data model

- `users` — plus `balance_nano`, `is_admin`.
- `generations` — one row per request: `uuid` (our id, used as Idempotency-Key and metadata), `provider_id` (`gen_…`), prompt/size/quality/refs, `status`, `estimated_cost_nano`, `estimated_max_nano`, `provider_cost_nano`, `charged_nano`, `usage`, `rates`, `image_url`, `image_expires_at`, `completed_at`.
- `webhook_events` — every accepted event; **unique `event_id`** is the dedupe key.
- `ledger_entries` — append-only credit movements (`purchase`, `charge`) with `balance_after_nano`; **unique `(generation_id, type)`** means a generation can be charged at most once.

## 5. Generating (`GenerationController`, `GenerationService`)

1. Validate (`StoreGenerationRequest`): prompt ≤ 4000, size, quality, ≤ 4 http(s) reference URLs.
2. Compute the estimate; reject if `availableNano` (balance minus worst-case of all in-flight generations) is below the worst-case price. Reserving in-flight jobs stops a user from starting many jobs they can't pay for.
3. Insert the `generations` row (`queued`), then `POST /v1/generations` with `Idempotency-Key = uuid` and `metadata.generation_uuid`. Retries (network errors, 503) reuse the key, so they can never create or bill a second generation.
4. Rejections (content policy, rate/concurrency limits) mark the row failed locally (`local_*` error code, free). The user is redirected to History.

## 6. Webhooks (`WebhookController`, `WebhookSignature`, `GenerationStateService`)

`POST /webhooks/pixelforge` (CSRF-exempt, public):

1. **Signature**: header `t=<unix>,v1=<hex>`; HMAC-SHA256 of `"<t>.<raw body>"` with `PIXELFORGE_WEBHOOK_SECRET`; constant-time compare; reject if the timestamp is more than 300 s off. Bad → 401 (Pixelforge retries).
2. **Dedupe**: inside a DB transaction, `insertOrIgnore` into `webhook_events` (unique `event_id`). Already seen → return 200 and do nothing. A concurrent duplicate blocks on the unique index until the first commits, then no-ops. If processing throws, the transaction rolls back (including the event row), so Pixelforge's retry is processed normally.
3. **Match**: by `provider_id`, or by `metadata.generation_uuid` — a webhook can arrive before the create-response has saved `provider_id`.
4. **Apply** (`GenerationStateService::apply`, row-locked):
   - `succeeded` and `failed` are final; nothing moves a generation backwards, so a late `processing` retry is harmless.
   - `queued → processing` only.
   - `succeeded`: store usage/rates/image, compute cost, charge once via `CreditService::chargeForGeneration` (ledger row + balance update under a user row lock).
   - `failed`: cost 0.
   - A row we failed locally (submit error) can still be corrected by a real `succeeded` event.
5. Always 200 once handled (including `ping` and unknown generations).

**Polling fallback**: `php artisan generations:sync` (scheduled every minute) fetches `GET /v1/generations/{id}` for non-terminal rows older than 60 s and feeds them through the same `apply()`, covering the case where every webhook attempt fails. Run `php artisan schedule:work` (or a cron calling `schedule:run`) in production.

## 7. History page

`GET /history` lists the user's generations. The Vue page re-fetches only the `generations` prop every 3 s while anything is queued or processing (Inertia partial reload), so status updates live. It shows estimated vs actual cost ("Pending" until final), the image, or the error. Expired image URLs (24 h) show a placeholder.

## 8. Credits and balances (`CreditController`, `CreditService`)

- Buy credits (`POST /credits`): fixed packs ($5/10/25/50) — a fake purchase that adds a ledger row and balance instantly.
- Generating is blocked when the user can't cover the worst-case price (server-side check, mirrored in the UI).
- The balance is shown in the nav; the Credits page shows the ledger.

## 9. Admin report (`AdminReportController`, `/admin/report`)

Operators only (`users.is_admin`, `EnsureAdmin` middleware; grant with `php artisan app:make-admin`). Shows credit spend (`charged_nano`), provider cost, and profit for each day, each user and each day × user. Only **succeeded** generations count, bucketed by the **UTC day they completed** — the same rule Pixelforge uses for `/v1/usage`. The page also calls `GET /v1/usage` for the date range and flags any day where Pixelforge's `cost_usd` differs from ours (✓ / ✗).

## 10. Frontend layout

`resources/js/Pages`: `Generate`, `History`, `Credits`, `Admin/Report`, `Welcome`, plus Breeze's `Auth/*` and `Profile/*`. `Layouts/AuthenticatedLayout.vue` is the shell (nav, balance). `money.js` formats nanodollars and mirrors the estimate.

## 11. Tests (`php artisan test`)

`WebhookTest` — bad signature, stale timestamp, duplicate delivery charged once, late `processing` after success, failed = free, matching before `provider_id` is stored. `GenerationFlowTest` — docs worst-case example ($0.373875), insufficient credits, idempotency key sent, in-flight reservation, buying credits, admin access and sums.

## 12. Deployment (Laravel Cloud)

Environment variables: `APP_KEY`, `APP_URL`, Postgres `DB_*`, `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, `QUEUE_CONNECTION`/`SESSION_DRIVER` as in `.env.example`. Run `php artisan migrate --force` on deploy, build assets with `npm run build`, and enable the scheduler. In the Pixelforge dashboard set the webhook URL to `https://<your-app>/webhooks/pixelforge`.
