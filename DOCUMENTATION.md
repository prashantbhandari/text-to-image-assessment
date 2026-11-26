# Imagen — how the project works

Imagen is a Laravel 13 + Inertia + Vue 3 + Tailwind app (PostgreSQL, MariaDB or MySQL). Signed-in users write a prompt, the backend sends it to **Pixelforge** (a simulated image-generation provider), and the finished image appears in the user's history. Users pay Pixelforge's cost plus a 25% markup, from a credit balance where **1 credit = 1 US dollar**. Operators get an admin console with spend, provider cost and profit, reconciled against Pixelforge's own usage report.

## Contents

1. [Architecture at a glance](#1-architecture-at-a-glance)
2. [Running it locally](#2-running-it-locally)
3. [Configuration](#3-configuration)
4. [Money model](#4-money-model)
5. [Pricing: estimate vs actual](#5-pricing-estimate-vs-actual)
6. [Data model](#6-data-model)
7. [Creating an image](#7-creating-an-image)
8. [Webhooks and the generation state machine](#8-webhooks-and-the-generation-state-machine)
9. [Credits and balances](#9-credits-and-balances)
10. [User interface](#10-user-interface)
11. [Admin console](#11-admin-console)
12. [Rate limiting and abuse protection](#12-rate-limiting-and-abuse-protection)
13. [Data retention and account deletion](#13-data-retention-and-account-deletion)
14. [Design system and theming](#14-design-system-and-theming)
15. [Code map](#15-code-map)
16. [Artisan commands](#16-artisan-commands)
17. [Routes](#17-routes)
18. [Tests](#18-tests)
19. [Deployment (your own server)](#19-deployment-your-own-server)
20. [Design decisions and limitations](#20-design-decisions-and-limitations)

---

## 1. Architecture at a glance

```
 Browser (Vue + Inertia)
    │  POST /generate                       ┌──────────────────────────┐
    ▼                                       │        Pixelforge        │
 GenerationController ──► GenerationService ─► POST /v1/generations     │
    │      (checks balance + capacity,      │   (Idempotency-Key = our │
    │       creates row, submits)           │    generation uuid)      │
    │                                       └───────────┬──────────────┘
    │                                                   │ webhooks (signed, at-least-once,
    ▼                                                   │ possibly duplicated / out of order)
 PostgreSQL ◄── GenerationStateService ◄── WebhookController (/webhooks/pixelforge)
 generations      (the ONE place that        verify HMAC → dedupe by event id → apply
 ledger_entries    changes status & bills)
 webhook_events         ▲
                        └── generations:sync (cron, every minute): polls Pixelforge for
                            generations whose webhooks never arrived
```

Key properties:

- **One writer.** `GenerationStateService::apply()` is the only code that moves a generation between states and charges the user. Webhooks and the polling fallback both call it, so they behave identically.
- **No queue worker needed.** Submission happens inside the web request; results arrive by webhook (or polling). Sessions and cache use the database.
- **Everything money-related is an integer** (nanodollars), so costs never drift.
- **Financial records are never destroyed** (soft deletes, restricted foreign keys; see §13).

## 2. Running it locally

Requirements: PHP 8.3+ with `pdo_pgsql`, Composer, Node 20+, PostgreSQL (Docker is easiest).

```sh
composer install && npm install
docker run -d --name pixelforge-pg -e POSTGRES_USER=app -e POSTGRES_PASSWORD=secret \
  -e POSTGRES_DB=pixelforge -p 5432:5432 postgres:17-alpine
cp .env.example .env && php artisan key:generate
# edit .env: PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET (whsec_...)
php artisan migrate
npm run build            # or `npm run dev` for hot reload (stop it when using a tunnel)
php artisan serve        # http://localhost:8000
php artisan app:make-admin you@example.com    # after registering
```

Pixelforge rejects private/loopback webhook URLs, so expose your local server (for example `ngrok http 8000`) and set the webhook URL in the Pixelforge dashboard to `https://<tunnel-host>/webhooks/pixelforge`. Set `APP_URL` to the public URL. Behind a tunnel or load balancer the app trusts `X-Forwarded-*` headers (`bootstrap/app.php`) so asset and redirect URLs stay `https://`.

Without a tunnel, `php artisan generations:sync --older-than=0` pulls results directly.

For tests create a second database once: `docker exec pixelforge-pg psql -U app -d pixelforge -c "CREATE DATABASE testing"`.

## 3. Configuration

**Environment (`.env`)**

| Variable | Purpose |
|---|---|
| `PIXELFORGE_BASE_URL` | API base, default `https://pixelforge.promptedit.dev/v1` |
| `PIXELFORGE_API_KEY` | Bearer key for outgoing requests |
| `PIXELFORGE_WEBHOOK_SECRET` | `whsec_…` secret of that key, used to verify webhook signatures |
| `DB_*` | PostgreSQL connection |
| `APP_URL`, `APP_KEY`, `APP_ENV`, `APP_DEBUG` | Standard Laravel |

**Application settings (`config/pixelforge.php`)**

| Key | Meaning |
|---|---|
| `markup_percent` | 25 → user price = cost × 1.25 |
| `default_rates` | Fallback USD-per-1M-token rates if `GET /v1/pricing` is unreachable |
| `output_tokens`, `reasoning_range` | Per quality/size token tables from the Pixelforge docs |
| `chars_per_text_token`, `tokens_per_reference_image`, `text_token_variance` | 4, 765, 0.15 |
| `max_reference_images` | 4 |
| `max_concurrent` | 3 images queued/processing at once (Pixelforge's per-account limit) |
| `max_daily_global`, `max_daily_per_user` | 500 (Pixelforge's daily limit) and 100 (our fair-use cap) |
| `stale_after_minutes` | 15: a queued row older than this no longer counts as used capacity |
| `credit_packs` | Fake purchase amounts in USD: 5, 10, 25, 50 |
| `webhook_tolerance` | 300 s allowed clock skew on webhook timestamps |
| `cron_token` | `CRON_TOKEN`: enables `GET /cron/<token>` to run the scheduler on hosts without a shell (empty = disabled) |

## 4. Money model

All amounts are **integer nanodollars** (1 USD = 1,000,000,000), stored in `bigint` columns named `*_nano`. Pixelforge cost is `tokens × rate ÷ 1,000,000`; with rates quoted in cents that is always a whole number of nanodollars, so nothing is rounded and our figures equal Pixelforge's 9-decimal `cost_usd` exactly. The only rounding is the 25% markup (nearest nanodollar).

`App\Services\Money` provides: rate → nanodollars per token, `withMarkup()`, `usdToNano()`, and `toDecimal()` (exact 9-decimal string). On the frontend `resources/js/money.js` formats values: **up to 4 decimals** for estimates and balances, **6** for actual charges and ledger rows, **9** in the admin report. Display rounding never affects stored values.

## 5. Pricing: estimate vs actual

`PricingService` holds both calculations.

| Component | Known before submit? | Source |
|---|---|---|
| Input text tokens | approximately (±15%) | `ceil(characters / 4)`, counted in Unicode characters |
| Reference image tokens | exactly | 765 per image |
| Output tokens | exactly | table by quality × size |
| Reasoning tokens | **no** | only a range per quality (e.g. medium 200–2000) |

- **Estimate** (Create page): *expected* price uses the midpoint of the reasoning range; a *likely range* (min–max) and a *worst case* (+15% text, maximum reasoning) are also computed. The browser calculates it live (`money.js`, a mirror of the PHP); the server recomputes on submit and stores `estimated_cost_nano` (expected price) and `estimated_max_nano` (worst case). The Create page also shows a separate estimate for each quality level.
- **Actual**: when a generation succeeds, `provider_cost_nano` = exact tokens × the rates **stored at creation** (Pixelforge fixes rates per generation), and `charged_nano` = cost + 25%.
- **Failed generations** cost nothing: both columns are 0.
- Rates come from `GET /v1/pricing` (cached 10 minutes), falling back to `default_rates`.

## 6. Data model

**`users`**: Breeze fields plus `balance_nano` and `is_admin` (not mass-assignable on purpose; set with `forceFill` or `app:make-admin`), `deleted_at`.

**`generations`**: one row per request.
`uuid` (our id; sent as Idempotency-Key and in metadata), `user_id`, `provider_id` (`gen_…`, unique), `prompt`, `size`, `quality`, `reference_images`, `status` (`queued` / `processing` / `succeeded` / `failed`), `estimated_cost_nano`, `estimated_max_nano`, `provider_cost_nano`, `charged_nano`, `usage`, `rates`, `image_url`, `image_expires_at`, `error_code`, `error_message`, `completed_at` (the UTC day it is billed on), `deleted_at`.

**`ledger_entries`**: append-only credit movements. `type` = `purchase` | `charge` | `adjustment`, `amount_nano` (signed), `balance_after_nano`, optional `note`. A unique index on `(generation_id, type)` guarantees one charge per generation.

**`webhook_events`**: every accepted event. Unique `event_id`; `type`, `generation_provider_id`, `payload`, `delivery_count`, `last_delivery_at`, `outcome` (`applied` / `ignored` / `unmatched`).

Foreign keys from `generations` and `ledger_entries` use `RESTRICT`, so the database itself refuses a hard delete of anything that has financial history.

## 7. Creating an image

`POST /generate` → `StoreGenerationRequest` (prompt ≤ 4000 chars; size and quality from the allowed lists; ≤ 4 http(s) reference URLs) → `GenerationService::create()`:

1. **Capacity check** (`ensureCapacity`): refuse early, creating nothing, if Pixelforge's shared limits would reject the request: 3 images already in flight, 500 generations today, or the user's own 100/day cap.
2. **Affordability**: compute the estimate; the user's *available* credits (balance minus the worst-case price of all their in-flight generations) must cover this request's worst case. This guarantees a balance can never go negative.
3. Insert the `generations` row (`queued`).
4. `POST /v1/generations` with `Idempotency-Key = uuid` and `metadata.generation_uuid`. Up to 3 attempts on network errors or 503, always with the same key, so a retry can never create or bill a second generation.
5. On acceptance the response snapshot goes through `GenerationStateService::apply()` (a webhook may already have advanced the row; it is never moved backwards). On rejection (content policy, rate limits) the row is marked failed locally (`local_*` error code, free) and the user sees a friendly message.

The user is redirected to History, which updates itself.

## 8. Webhooks and the generation state machine

`POST /webhooks/pixelforge` (public, CSRF-exempt, rate-limited at 600/min/IP):

1. **Verify the signature** (`WebhookSignature`): header `t=<unix>,v1=<hex>`; expected `HMAC-SHA256(secret, "<t>.<raw body>")`, compared in constant time; timestamps more than 300 s from now are rejected. Failure → 401 (Pixelforge retries).
2. **Deduplicate** inside one DB transaction: `insertOrIgnore` into `webhook_events` keyed by `event_id`. If the event already exists, only `delivery_count` is incremented and nothing else happens, so a duplicate is never recorded or billed twice. Concurrent duplicates serialise on the unique index. If processing throws, the transaction (including the event row) rolls back and Pixelforge's retry is handled normally.
3. **Match** the generation by `provider_id`, or by `metadata.generation_uuid`. The uuid fallback covers a webhook that arrives before the create-response has stored `provider_id`.
4. **Apply** (`GenerationStateService::apply`, with the row locked `FOR UPDATE`):
   - `succeeded` and `failed` are final; nothing moves a generation backwards, so a late or retried `processing` is harmless.
   - `queued → processing` only.
   - `succeeded`: store usage, rates and image URL; compute cost; charge once through `CreditService::chargeForGeneration` (ledger row + balance update under a user row lock).
   - `failed`: record the error; cost 0.
   - A generation we failed locally (submit error) is still corrected by a genuine `succeeded` event, so we are never left unbilled for something Pixelforge billed us for.
5. The outcome is recorded: `applied` (it moved the generation), `ignored` (stale/out of order) or `unmatched`. Respond 200 (also for `ping` events).

**Polling fallback.** `generations:sync` runs every minute (scheduler) and fetches `GET /v1/generations/{id}` for queued/processing rows older than 60 s, feeding them to the same `apply()`. The scheduled job runs in-process (`Schedule::call`) rather than spawning `php artisan`, because many shared hosts disable process spawning. Hosts whose cron can only fetch a URL can call `GET /cron/<CRON_TOKEN>` (`CronController`: 404 unless the token is set and matches, constant-time compare, rate-limited), which runs `schedule:run`.

## 9. Credits and balances

`CreditService`; every movement writes a ledger row while the `users` row is locked, so balance and ledger cannot drift.

- **Purchase** (`POST /credits`): a fake "Buy credits" for $5/10/25/50. It adds credits instantly, with no payment.
- **Charge**: one per successful generation (see §8).
- **Adjustment**: an admin gives or takes credits (§11). The result may not go below zero; the optional note is shown to the user in their activity list.
- **Blocking**: creation is refused unless available credits cover the worst-case price. New users start at $0 and must buy credits first.

## 10. User interface

All pages are Inertia + Vue under `resources/js/Pages`, with the app shell in `Layouts/AuthenticatedLayout.vue` (nav, balance chip, avatar, theme toggle).

- **Create** (`/generate`): prompt with example chips and Ctrl+Enter; shape tiles drawn at their real aspect ratio; quality cards each showing their own live price; optional reference URLs with thumbnails; a sticky price card (expected price, likely-range bar with marker, available credits, and an "Add credits" prompt when unaffordable). `?reuse=<id>` pre-fills everything from an earlier image.
- **History** (`/history`): a uniform gallery grid. Every image sits in an identical 4:3 frame, contained over a blurred copy of itself so portrait, landscape and square all look consistent. Filters (All / In progress / Done / Failed) with counts; live polling every 3 s while anything is active; status badges; **estimated vs actual** cost with the difference; skeleton shimmer for in-progress items; long prompts clamp to 3 lines with Show more / less; click-to-zoom lightbox (centered, scrollable caption). Each card has icon actions:
  - **Download**: `GET /generations/{id}/download` streams the image through our server (browsers ignore `download` on cross-origin URLs); refuses expired links (410).
  - **Copy prompt**.
  - **Reuse**: opens Create with prompt, size, quality and reference URLs filled in.
  - **Delete**: confirmation dialog; only for finished generations; a soft delete (§13).

  Images stop loading after Pixelforge's 24-hour URL expiry; the card shows a placeholder.
- **Credits** (`/credits`): balance, pack buttons, and the activity ledger (purchases, image charges, adjustments).
- **Auth**: Breeze login/register/reset/profile, restyled; login links to registration. Rate-limit rejections appear as a toast (`Components/Toast.vue`).

## 11. Admin console

Operators only (`users.is_admin`, `EnsureAdmin` middleware → 403 otherwise; grant with `app:make-admin`). Separate sidebar layout (`Layouts/AdminLayout.vue`): Report, Users, Webhooks, Back to app.

**Report** (`/admin/report`, `AdminReportService`)
- Date range picker (+ 7/30/90-day presets); default last 30 days, maximum 92 days (Pixelforge's limit), invalid input falls back safely.
- Totals: credit spend (charged to users), provider cost (billed by Pixelforge), profit and margin, generations billed.
- Tables by **day**, by **user**, and **day × user**; a daily revenue-vs-cost chart.
- Counts only **succeeded** generations, bucketed by the **UTC day they completed**, the same rule Pixelforge's `GET /v1/usage` uses. Soft-deleted generations and deleted users are included, so deleting from a user's history never changes the books.
- **Reconciliation**: calls `GET /v1/usage` for the same range and compares cost and generation count per day. A banner shows ✓ or the number of differing days; "Show day-by-day check" lists ours, theirs, the difference and counts per day.
- **CSV export** of the current view (`/admin/report/export?view=day|user|day_user`) with exact 9-decimal amounts; user-controlled text starting with `= + - @` is prefixed with `'` to prevent spreadsheet formula injection.

**Users** (`/admin/users`): searchable, paginated list with balance, lifetime spend, images, failures and join date. **Adjust credits** dialog accepts dollars with up to 2 decimals (`5`, `-2.50`), rejects zero, bad input and anything that would make the balance negative, and writes a ledger entry with an optional note.

**Webhooks** (`/admin/webhooks`): every accepted event with type, generation, user, prompt, **delivery count** (×2 or more means Pixelforge re-sent it) and **outcome**. Counters for received, re-delivered, ignored and unmatched events; filters by type, text and duplicates/ignored.

## 12. Rate limiting and abuse protection

Named limiters are defined in `AppServiceProvider::configureRateLimiting()` and attached with `throttle:<name>`. Authenticated routes are limited per user, public ones per IP.

| Limiter | Applies to | Limit |
|---|---|---|
| (in `LoginRequest`) | login | 5 failed attempts per email + IP, then locked out |
| `login` | login | 20/min per IP (stops one address trying many emails) |
| `register` | register | 5/min and 20/hour per IP |
| `sensitive` | forgot/reset/confirm/change password, delete account | 5/min and 30/hour |
| `generate` | create image | 6/min and 60/hour per user |
| `actions` | buy credits, delete image, edit profile, admin credit adjustment | 30/min per user |
| `webhook` | `/webhooks/pixelforge` | 600/min per IP (generous; a dropped event only delays a result) |

Browsers receive a redirect back with a toast ("Too many attempts. Please try again in N seconds"); JSON clients get the standard 429 with `Retry-After`. Pixelforge's own account-wide limits (3 concurrent, 500/day) are enforced up front by `GenerationService::ensureCapacity()` (§7).

## 13. Data retention and account deletion

Pixelforge bills us for every successful generation regardless of what a user later does, so our records of it must survive.

- **Deleting an image** from History soft-deletes the row (`deleted_at`). Its charge, ledger entry and provider cost remain in the report. Deleted rows are still found by webhooks and polling. In-flight images cannot be deleted.
- **Deleting an account** anonymises the user (name "Deleted user", email `deleted-<id>@deleted.invalid`, random password) and soft-deletes it. They can no longer sign in; the original email is free to register again; their generations and ledger stay and appear as "Deleted user" in the report.
- The foreign keys use `RESTRICT`, so the database refuses a hard delete of a user or generation that has history.

(An early version cascade-deleted a deleted user's generations, which made the report fall short of Pixelforge's `/v1/usage`. The reconciliation caught it, and this design is the fix.)

## 14. Design system and theming

Direction: a photographer's "darkroom" — warm charcoal with a single ember-orange accent; serif display type (Fraunces) for headlines, Inter for the interface; an aperture logo.

- **Tokens** live as CSS variables in `resources/css/app.css` (`--app`, `--surface`, `--surface-2`, `--line`, `--fg`, `--muted`, `--brand`, `--accent`, `--accent-fg`) and are exposed as Tailwind colors (`bg-surface`, `text-muted`, `bg-accent`…). Colors are RGB triplets so opacity works (`bg-accent/10`).
- **Dark is the default.** Light is warm paper with the same accent, deepened for contrast. The theme is stored in `localStorage` and applied by an inline script in `app.blade.php` before first paint, so there is no flash. `Components/ThemeToggle.vue` switches it.
- **Contrast** targets WCAG AA (≥ 4.5:1) in both themes; accent buttons use dark text in dark mode and white in light mode. Status colors use tinted backgrounds that adapt per theme.
- **Utilities**: `.btn-accent`, `.btn-ghost`, `.card`, `.icon-btn`, `.shimmer`, `.fade-up`, a subtle film-grain overlay, and `prefers-reduced-motion` support.
- **Shared components**: `BrandMark`, `StatusBadge`, `ThemeToggle`, `Toast`, `Pagination`, plus the restyled Breeze components.

## 15. Code map

```
app/
  Console/Commands/      SyncGenerations (webhook fallback), MakeAdmin
  Http/Controllers/      GenerationController (create/store/history/download/destroy), CreditController,
                         WebhookController, ProfileController, Admin/{Report,User,WebhookEvent}Controller
  Http/Middleware/       EnsureAdmin, HandleInertiaRequests (shares auth user, balance, flash errors)
  Http/Requests/         StoreGenerationRequest
  Models/                User, Generation, LedgerEntry, WebhookEvent
  Services/              Money, PricingService, PixelforgeClient, GenerationService,
                         GenerationStateService, CreditService, WebhookSignature, AdminReportService
  Providers/AppServiceProvider.php   rate limiters
config/pixelforge.php    all pricing constants and limits
database/migrations/     users billing columns, generations, webhook_events, ledger_entries,
                         soft deletes, admin columns, financial-record protection
resources/js/
  Pages/                 Generate, History, Credits, Welcome, Admin/{Report,Users,Webhooks}, Auth/*, Profile/*
  Layouts/               AuthenticatedLayout, AdminLayout, GuestLayout
  Components/            see §14   •   money.js: formatting + client-side estimate
routes/web.php, routes/auth.php, routes/console.php (scheduler)
tests/Feature/           see §18
```

## 16. Artisan commands

| Command | Purpose |
|---|---|
| `php artisan app:make-admin <email>` | Grant admin access (the account must already exist) |
| `php artisan generations:sync [--older-than=60]` | Poll Pixelforge for stuck queued/processing generations (scheduled every minute) |
| `php artisan migrate` | Apply schema changes |
| `php artisan test` | Run the test suite |
| `php artisan schedule:work` | Run the scheduler locally |

## 17. Routes

| Method & path | Name | Notes |
|---|---|---|
| `GET /` | | Landing page |
| `POST /webhooks/pixelforge` | `webhooks.pixelforge` | Public, CSRF-exempt, signature-verified |
| `GET /generate`, `POST /generate` | `generations.create/store` | `?reuse=<id>` prefill; store throttled |
| `GET /history` | `generations.index` | |
| `GET /generations/{id}/download` | `generations.download` | Own images only |
| `DELETE /generations/{id}` | `generations.destroy` | Own, finished images only |
| `GET /credits`, `POST /credits` | `credits.index/purchase` | |
| `/profile` (GET/PATCH/DELETE) | `profile.*` | |
| `/admin/report`, `/admin/report/export` | `admin.report`, `admin.report.export` | Admin |
| `/admin/users`, `POST /admin/users/{user}/credits` | `admin.users`, `admin.users.credits` | Admin |
| `/admin/webhooks` | `admin.webhooks` | Admin |
| `GET /cron/{token}` | `cron` | Runs the scheduler by URL; 404 unless `CRON_TOKEN` matches |
| login, register, password reset, verify, logout | Breeze (`routes/auth.php`) | Rate limited |

`/dashboard` redirects to `/generate`. Email verification is available but not required.

## 18. Tests

`php artisan test` (PostgreSQL `testing` database; Pixelforge calls are faked).

- **WebhookTest**: bad signature, stale timestamp, duplicate delivery charged once, late `processing` after success, failed = free, match by metadata before `provider_id` is stored.
- **GenerationFlowTest**: documented worst-case price ($0.373875), insufficient credits, Idempotency-Key sent, in-flight reservation, buying credits, admin report access and sums.
- **HistoryActionsTest**: reuse prefill (and cross-user isolation), soft delete keeps the books, in-flight/other-user delete refused, download streaming/expiry/ownership, account deletion preserves records and frees the email, DB refuses hard delete.
- **RateLimitingTest**: login lockout and per-IP cap, register, forgot-password, image creation, Pixelforge concurrency and daily caps, stuck rows ignored, credit purchases, JSON 429.
- **AdminSectionTest**: admin access control, date-range bucketing and clamping, reconciliation mismatch detection, CSV exactness and formula escaping, credit adjustments and rejection rules, users page totals (including deleted generations), webhook delivery counts and outcomes, `app:make-admin`.
- Breeze's auth and profile tests are retained (profile deletion updated for soft delete).

## 19. Deployment (your own server)

**Databases.** PostgreSQL, MariaDB (10.3+) and MySQL (5.7+/8) are supported; set `DB_CONNECTION` to `pgsql`, `mariadb` or `mysql`. The code uses no database-specific SQL (admin search uses `LOWER(col) LIKE ?`, not `ILIKE`; money is integer `bigint`; `DATE()` for day buckets). The whole suite passes on PostgreSQL 17, MariaDB 10.6 and MySQL 8.0 (including MySQL's strict `ONLY_FULL_GROUP_BY`).

**PHP version.** `composer.json` allows PHP `^8.3`, but the packages currently in `composer.lock` (Symfony 8) require **PHP 8.4.1+** at runtime (`vendor/composer/platform_check.php`). For an 8.3 host re-lock first: `composer config platform.php 8.3.0 && composer update` (tested: resolves to Symfony 7.4, nothing needs 8.4).

**With a shell** (VPS / dedicated):

1. Copy `.env.example` to `.env`; set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, the `DB_*` values, `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`; run `php artisan key:generate` once.
2. On each deploy: `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan optimize`.
3. Add a cron entry running `php artisan schedule:run` every minute.
4. Make `storage/` and `bootstrap/cache/` writable by the web user.
5. In the Pixelforge dashboard set the webhook URL to `https://<your-domain>/webhooks/pixelforge`. Proxy headers are trusted, so URLs stay `https` behind a load balancer.
6. Create the first operator: register, then `php artisan app:make-admin you@example.com`.

**Without a shell** (shared hosting with a control panel):

- Build on your own computer (`composer install --no-dev --optimize-autoloader`, `npm run build`) and upload `vendor/` and `public/build/` along with the code.
- Create the tables by importing `database/install/imagen-mariadb.sql` in phpMyAdmin. It is generated from the real migrations, creates the 12 tables (with the `RESTRICT` foreign keys) and records every migration as applied. Later migrations are applied by running the output of `php artisan migrate --pretend` in phpMyAdmin.
- Grant the first admin with SQL: `UPDATE users SET is_admin = 1 WHERE email = '...'`.
- Scheduler: a panel cron job running `php artisan schedule:run`, or, if cron can only fetch URLs, set `CRON_TOKEN` and request `https://<domain>/cron/<token>` every minute.
- The app needs no cache, queue worker or storage symlink, so nothing has to be run on the server after upload.

A step-by-step guide for this setup is kept in `SERVER_SETUP.md` (not committed).

## 20. Design decisions and limitations

- **Estimate, not quote.** Reasoning tokens are unknown until completion, so the up-front price is an expected value with a range; the user is charged the exact final cost. Balances are protected by reserving the worst case.
- **Sub-cent costs** are handled with exact integers rather than rounding to cents.
- **Idempotency everywhere**: creation (Idempotency-Key), webhooks (unique event id), billing (unique ledger key + `charged_nano` check), state (row lock, terminal states final).
- **Images are not stored.** Pixelforge URLs expire after 24 hours, after which History shows a placeholder (permitted by the brief). Download within 24 hours to keep an image.
- **Submission is synchronous** (a few hundred ms); a queue worker could move it out of the request if needed.
- **Fake payments.** "Buy credits" and admin adjustments only change balances.
- **The report assumes one Pixelforge API key**, which is how `/v1/usage` scopes its data.
- **Per-user daily cap (100)** is our own fair-use choice, not a Pixelforge rule; change it in `config/pixelforge.php`.
