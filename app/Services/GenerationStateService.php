<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a Pixelforge generation snapshot (from a webhook or from polling)
 * to our local row. This is the single place where state transitions and
 * billing happen, so webhooks and the polling fallback behave identically.
 *
 * Rules that make it safe against duplicate / out-of-order delivery:
 *  - the row is locked, so concurrent deliveries serialize;
 *  - succeeded/failed are final: nothing moves a generation backwards;
 *  - queued -> processing only; a late "processing" never overwrites a result;
 *  - charging is idempotent (see CreditService::chargeForGeneration).
 */
class GenerationStateService
{
    public function __construct(private PricingService $pricing, private CreditService $credits) {}

    /**
     * @param  array<string, mixed>  $snapshot  Pixelforge generation object
     */
    public function apply(Generation $generation, array $snapshot): Generation
    {
        return DB::transaction(function () use ($generation, $snapshot) {
            /** @var Generation $g */
            $g = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if (! $g->provider_id && isset($snapshot['id'])) {
                $g->provider_id = $snapshot['id'];
            }

            $incoming = $snapshot['status'] ?? null;

            // A row we failed locally (submit error, no provider id) may still turn
            // out to have been accepted by Pixelforge; let a real result override it.
            $locallyFailed = $g->status === Generation::FAILED && $g->getOriginal('provider_id') === null
                && str_starts_with((string) $g->error_code, 'local_');

            if ($g->isTerminal() && ! ($locallyFailed && $incoming === Generation::SUCCEEDED)) {
                $g->save(); // persist provider_id if it was newly learned

                return $g;
            }

            match ($incoming) {
                Generation::PROCESSING => $this->markProcessing($g),
                Generation::SUCCEEDED => $this->markSucceeded($g, $snapshot),
                Generation::FAILED => $this->markFailed($g, $snapshot['error']['code'] ?? 'generation_failed', $snapshot['error']['message'] ?? null, $snapshot['completed_at'] ?? null),
                default => null,
            };

            $g->save();

            return $g;
        });
    }

    /** Fail a generation for a local reason (e.g. submit rejected). Costs nothing. */
    public function failLocally(Generation $g, string $code, string $message): void
    {
        DB::transaction(function () use ($g, $code, $message) {
            $locked = Generation::query()->lockForUpdate()->findOrFail($g->id);
            if (! $locked->isTerminal()) {
                $this->markFailed($locked, $code, $message, null);
                $locked->charged_nano = 0;
                $locked->save();
            }
        });
    }

    private function markProcessing(Generation $g): void
    {
        if ($g->status === Generation::QUEUED) {
            $g->status = Generation::PROCESSING;
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function markSucceeded(Generation $g, array $snapshot): void
    {
        $rates = $snapshot['rates'] ?? $g->rates ?? $this->pricing->rates();
        $usage = $snapshot['usage'] ?? [];

        // Actual cost: exact from the usage Pixelforge reported and the rates
        // fixed at creation time. User price = cost + markup.
        $cost = $this->pricing->cost($usage, $rates);
        $price = Money::withMarkup($cost);

        $g->status = Generation::SUCCEEDED;
        $g->usage = $usage;
        $g->rates = $rates;
        $g->image_url = $snapshot['image_url'] ?? null;
        $g->image_expires_at = isset($snapshot['image_url_expires_at']) ? Carbon::parse($snapshot['image_url_expires_at']) : null;
        $g->completed_at = isset($snapshot['completed_at']) ? Carbon::parse($snapshot['completed_at']) : now();
        $g->error_code = null;
        $g->error_message = null;
        $g->provider_cost_nano = $cost;

        // A locally-failed row carries charged_nano = 0; a real success must be billable.
        if ($g->charged_nano === 0) {
            $g->charged_nano = null;
        }

        $this->credits->chargeForGeneration($g, $price);
    }

    private function markFailed(Generation $g, string $code, ?string $message, ?string $completedAt): void
    {
        $g->status = Generation::FAILED;
        $g->error_code = $code;
        $g->error_message = $message;
        $g->completed_at = $completedAt ? Carbon::parse($completedAt) : now();
        $g->provider_cost_nano = 0;
        $g->charged_nano = 0; // failed generations cost nothing
    }
}
