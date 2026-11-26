<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Log of every Pixelforge webhook event we accepted: how many times it was delivered
 * and what we did with it. Makes duplicate and out-of-order delivery visible.
 */
class WebhookEventController extends Controller
{
    public function index(Request $request): Response
    {
        $type = $request->query('type');
        $search = trim((string) $request->query('q', ''));
        $only = $request->query('only'); // 'duplicates' | 'ignored'

        $events = WebhookEvent::query()
            ->with('generation.user:id,name,email')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('event_id', 'ilike', "%{$search}%")->orWhere('generation_provider_id', 'ilike', "%{$search}%")))
            ->when($only === 'duplicates', fn ($q) => $q->where('delivery_count', '>', 1))
            ->when($only === 'ignored', fn ($q) => $q->where('outcome', 'ignored'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (WebhookEvent $e) => [
                'id' => $e->id,
                'event_id' => $e->event_id,
                'type' => $e->type,
                'provider_id' => $e->generation_provider_id,
                'user' => $e->generation?->user?->email,
                'prompt' => $e->generation ? mb_strimwidth($e->generation->prompt, 0, 70, '…') : null,
                'delivery_count' => $e->delivery_count,
                'outcome' => $e->outcome,
                'received_at' => $e->created_at->toIso8601String(),
                'last_delivery_at' => $e->last_delivery_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Webhooks', [
            'events' => $events,
            'filters' => ['type' => $type, 'q' => $search, 'only' => $only],
            'types' => WebhookEvent::query()->distinct()->orderBy('type')->pluck('type'),
            'stats' => [
                'total' => WebhookEvent::count(),
                'duplicates' => WebhookEvent::where('delivery_count', '>', 1)->count(),
                'ignored' => WebhookEvent::where('outcome', 'ignored')->count(),
                'unmatched' => WebhookEvent::where('outcome', 'unmatched')->count(),
            ],
        ]);
    }
}
