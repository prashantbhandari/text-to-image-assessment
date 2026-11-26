<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Services\CreditService;
use App\Services\GenerationService;
use App\Services\PricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Generate page (create form with live estimate) and history page.
 */
class GenerationController extends Controller
{
    public function create(Request $request, PricingService $pricing, CreditService $credits): Response
    {
        $cfg = config('pixelforge');

        return Inertia::render('Generate', [
            // Everything the browser needs to compute the estimate live. The
            // server recomputes it authoritatively on submit.
            'pricing' => [
                'rates' => $pricing->rates(),
                'markup_percent' => $cfg['markup_percent'],
                'chars_per_text_token' => $cfg['chars_per_text_token'],
                'tokens_per_reference_image' => $cfg['tokens_per_reference_image'],
                'text_token_variance' => $cfg['text_token_variance'],
                'output_tokens' => $cfg['output_tokens'],
                'reasoning_range' => $cfg['reasoning_range'],
                'max_reference_images' => $cfg['max_reference_images'],
            ],
            'available_nano' => $credits->availableNano($request->user()),
        ]);
    }

    public function store(StoreGenerationRequest $request, GenerationService $service): RedirectResponse
    {
        $service->create($request->user(), $request->validated());

        return redirect()->route('generations.index');
    }

    public function index(Request $request): Response
    {
        $generations = Generation::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Generation $g) => [
                'id' => $g->id,
                'prompt' => $g->prompt,
                'size' => $g->size,
                'quality' => $g->quality,
                'status' => $g->status,
                'estimated_cost_nano' => $g->estimated_cost_nano,
                'charged_nano' => $g->charged_nano,
                // Pixelforge URLs expire after 24h; hide them so the UI shows a placeholder.
                'image_url' => $g->image_url && (! $g->image_expires_at || $g->image_expires_at->isFuture()) ? $g->image_url : null,
                'image_expired' => (bool) ($g->image_url && $g->image_expires_at && $g->image_expires_at->isPast()),
                'error_message' => $g->error_message,
                'created_at' => $g->created_at->toIso8601String(),
            ]);

        return Inertia::render('History', ['generations' => $generations]);
    }
}
