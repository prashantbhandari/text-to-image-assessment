<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationStateService;
use App\Services\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Receives Pixelforge webhooks.
 *
 * Flow: verify signature on the RAW body -> dedupe by event id -> apply the
 * generation snapshot under a row lock. Delivery is at-least-once and may be
 * out of order, so every step is idempotent.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, GenerationStateService $state): JsonResponse
    {
        $raw = $request->getContent();

        if (! $signature->verify($raw, $request->header('Pixelforge-Signature'), (string) config('pixelforge.webhook_secret'), (int) config('pixelforge.webhook_tolerance'))) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $event = json_decode($raw, true);
        $eventId = $event['id'] ?? null;
        if (! is_array($event) || ! $eventId) {
            return response()->json(['error' => 'invalid_payload'], 400);
        }

        $snapshot = $event['data']['object'] ?? [];

        // ping (dashboard "Send test event") carries no generation.
        if (($event['type'] ?? '') === 'ping' || ! isset($snapshot['id'])) {
            return response()->json(['received' => true]);
        }

        DB::transaction(function () use ($event, $eventId, $snapshot, $state) {
            // Dedupe: the unique index on event_id makes this insert a no-op for a
            // repeated delivery (a concurrent duplicate waits here for the first commit).
            $inserted = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $eventId,
                'type' => $event['type'] ?? 'unknown',
                'generation_provider_id' => $snapshot['id'],
                'payload' => json_encode($event),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                // Re-delivery of an event we already handled: count it, change nothing else.
                WebhookEvent::query()->where('event_id', $eventId)->update([
                    'delivery_count' => DB::raw('delivery_count + 1'),
                    'last_delivery_at' => now(),
                ]);

                return;
            }

            // Match by provider id, or by the uuid we sent as metadata (the webhook can
            // beat the create-response, before we have stored provider_id).
            $uuid = $snapshot['metadata']['generation_uuid'] ?? null;
            $generation = Generation::withTrashed()
                ->where('provider_id', $snapshot['id'])
                ->when($uuid, fn ($q) => $q->orWhere('uuid', $uuid))
                ->first();

            $outcome = 'unmatched';
            if ($generation) {
                $before = [$generation->status, $generation->charged_nano];
                $after = $state->apply($generation, $snapshot);
                // "applied" only if the event actually moved the generation forward.
                $outcome = [$after->status, $after->charged_nano] !== $before ? 'applied' : 'ignored';
            }

            WebhookEvent::query()->where('event_id', $eventId)->update(['outcome' => $outcome, 'last_delivery_at' => now()]);
        });

        return response()->json(['received' => true]);
    }
}
