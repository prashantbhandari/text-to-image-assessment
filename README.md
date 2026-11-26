# Imagen

A Laravel app where signed-in users generate images with the **Pixelforge** API and pay per image. Requests are sent to Pixelforge in the background, results arrive by webhook, and the generated images show up in the user's history once they finish.

**Stack:** Laravel 13 · Inertia · Vue 3 · Tailwind CSS · PostgreSQL

## What it does

- **Generate**: write a prompt, pick a size and quality, optionally add up to 4 reference image URLs. The estimated cost is shown before you submit.
- **Pricing**: users pay Pixelforge's cost plus a 25% markup. The price shown up front is an estimate, because reasoning tokens are only known after completion. When a generation finishes, the actual cost is recorded. Failed generations are free.
- **History**: live status (queued, processing, succeeded, failed), estimated and actual cost, and the image.
- **Webhooks**: signatures are verified, duplicate events are ignored, and out-of-order events can't undo a finished generation. A polling command acts as a fallback.
- **Credits**: users have a balance (credits are US dollars). A "Buy credits" button adds fake credits, and generating is blocked when the user can't afford it.
- **Admin report**: operators see credit spend, provider cost and profit per day and per user, checked against Pixelforge's `GET /v1/usage`.

Costs are stored as integer nanodollars (1 USD = 1,000,000,000), so sub-cent amounts are exact. See [DOCUMENTATION.md](DOCUMENTATION.md) for how everything works in detail.

## Requirements

- PHP 8.3+ with `pdo_pgsql` (the project uses 8.5) and Composer
- Node.js 20+ and npm
- PostgreSQL 15+ (Docker is the easiest way)
- A Pixelforge API key and its webhook signing secret (`whsec_…`)

## Setup

```sh
# 1. Install dependencies
composer install
npm install

# 2. Start PostgreSQL (or use your own server)
docker run -d --name pixelforge-pg \
  -e POSTGRES_USER=app -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=pixelforge \
  -p 5432:5432 postgres:17-alpine

# 3. Configure the environment
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set the Pixelforge values:

```
PIXELFORGE_API_KEY=your-api-key
PIXELFORGE_WEBHOOK_SECRET=whsec_your-secret
```

The database settings in `.env.example` already match the Docker command above.

```sh
# 4. Create the tables
php artisan migrate

# 5. Build the frontend and run the app
npm run build
php artisan serve
```

Open http://localhost:8000, register, and use **Credits** to add test money before generating.

For development with hot reload, use `composer run dev` instead of step 5. It runs the server, queue worker, scheduler and Vite together.

### Make yourself an admin

After registering:

```sh
php artisan app:make-admin you@example.com
```

An **Admin report** link then appears in the nav.

### Receive webhooks

Pixelforge must be able to reach your app over the public internet and rejects private or localhost URLs.

1. Expose your local server with a tunnel, for example `ngrok http 8000`.
2. In the Pixelforge dashboard, set the webhook URL for your API key to `https://<your-host>/webhooks/pixelforge`.
3. Copy that key's signing secret into `PIXELFORGE_WEBHOOK_SECRET`.
4. Set `APP_URL` in `.env` to the public URL.

If webhooks can't reach you, run `php artisan generations:sync --older-than=0` (also scheduled every minute by `php artisan schedule:work`) to pull results directly.

### Testing with Pixelforge keywords

Put these in a prompt to trigger behaviour: `[succeed]`, `[fail]`, `[slow]`, `[duplicate]` (every webhook delivered twice), `[blocked]`, `[overloaded]`.

## Tests

```sh
docker exec pixelforge-pg psql -U app -d pixelforge -c "CREATE DATABASE testing"   # once
php artisan test
```

## Deployment

Works on Laravel Cloud or any PHP host. Set the same environment variables as `.env`, run `php artisan migrate --force` and `npm run build` on deploy, and run the scheduler (`php artisan schedule:run` every minute). Then point the Pixelforge webhook URL at `https://<your-app>/webhooks/pixelforge`.

## Project layout

| Path | Purpose |
|---|---|
| `app/Services/` | Pricing, credits, generation submit, state machine, webhook signature, Pixelforge client |
| `app/Http/Controllers/` | Generate/History, Credits, Admin report, Webhook |
| `app/Console/Commands/` | `generations:sync`, `app:make-admin` |
| `resources/js/Pages/` | Vue pages: Generate, History, Credits, Admin/Report |
| `config/pixelforge.php` | Pricing constants, markup, credit packs |
| `tests/Feature/` | Webhook and generation-flow tests |
