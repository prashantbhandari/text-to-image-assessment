# Imagen Pixelforge Assessment

A Laravel app where signed-in users generate images with the **Pixelforge** API and pay per image. Requests are sent to Pixelforge in the background, results arrive by webhook, and the generated images show up in the user's history once they finish.

**Stack:** Laravel 13 · Inertia · Vue 3 · Tailwind CSS · PostgreSQL (also runs on MariaDB / MySQL)

## What it does

- **Create**: write a prompt, pick a shape and quality, optionally add up to 4 reference image URLs. A live estimate (expected price, likely range, and a price for each quality level) is shown before you submit.
- **Pricing**: users pay Pixelforge's cost plus a 25% markup. The up-front price is an estimate because reasoning tokens are only known after completion; when a generation finishes, the exact cost is recorded. Failed generations are free.
- **History**: a gallery with live status (queued, processing, done, failed), estimated vs actual cost, filters, a zoomable preview, and per-image actions: **download, copy prompt, reuse** (re-opens Create with all settings filled in) and **delete**.
- **Webhooks**: signatures are verified, duplicate events are ignored, out-of-order events can't undo a finished generation, and a polling command covers webhooks that never arrive.
- **Credits**: each user has a balance (credits are US dollars). "Buy credits" adds fake credits, and creating an image is blocked when the user can't afford the worst case.
- **Admin console** (operators only): a **Report** (spend, provider cost and profit by day and user, date range, CSV export, reconciled against Pixelforge's `GET /v1/usage`), a **Users** page (balances, spend, credit adjustments) and a **Webhooks** log (deliveries and outcomes).
- **Protection**: rate limits on login, registration, password flows, image creation and credit actions, plus Pixelforge-aware capacity checks.
- **Design**: warm "darkroom" theme, dark by default with a light mode toggle.

Costs are stored as integer nanodollars (1 USD = 1,000,000,000), so sub-cent amounts are exact. Financial records are never deleted: removing an image or an account keeps the books intact. See [DOCUMENTATION.md](DOCUMENTATION.md) for how everything works in detail.

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

An **Admin** link then appears in the nav (it opens the operator console).

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

## Deployment (your own server)

Works with **PostgreSQL, MariaDB (10.3+) or MySQL (5.7+/8)**: the full test suite passes on PostgreSQL 17, MariaDB 10.6 and MySQL 8.0. Set `DB_CONNECTION` to `pgsql`, `mariadb` or `mysql`.

**With a shell** (VPS / dedicated server): PHP 8.3+ with the database extension, a web server with its document root at `public/`, HTTPS and Node.js for the build.

```sh
git pull && composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize          # caches config, routes and views
```

- **Environment:** copy `.env.example` to `.env` and set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` (your public https URL), the `DB_*` values, `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`. Run `php artisan key:generate` once.
- **Scheduler:** add one cron line so missed webhooks are polled every minute: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`
- **Permissions:** `storage/` and `bootstrap/cache/` must be writable by the web user.
- **Webhook:** in the Pixelforge dashboard set the URL to `https://<your-domain>/webhooks/pixelforge`. The route skips CSRF and is verified by signature; trusted proxy headers are enabled for setups behind a load balancer.
- **First admin:** register, then `php artisan app:make-admin you@example.com`.

**Without a shell** (shared hosting with a control panel and phpMyAdmin): build on your computer (`composer install --no-dev`, `npm run build`), upload with the File Manager, and import `database/install/imagen-mariadb.sql` in phpMyAdmin instead of running migrations. If cron can only fetch a URL, set `CRON_TOKEN` and call `https://<your-domain>/cron/<token>` every minute. Note the PHP version requirement: the packages currently locked need PHP 8.4.1+ (re-lock with `composer config platform.php 8.3.0 && composer update` for PHP 8.3 hosts).

## Project layout

| Path | Purpose |
|---|---|
| `app/Services/` | Pricing, credits, generation submit, state machine, webhook signature, Pixelforge client, admin report |
| `app/Http/Controllers/` | Generate/History, Credits, Webhook, Profile, and `Admin/` (Report, Users, Webhook log) |
| `app/Console/Commands/` | `generations:sync`, `app:make-admin` |
| `resources/js/Pages/` | Vue pages: Generate, History, Credits, Admin/{Report,Users,Webhooks} |
| `config/pixelforge.php` | Pricing constants, markup, limits, credit packs |
| `tests/Feature/` | Webhooks, generation flow, history actions, rate limiting, admin console (66 tests) |
