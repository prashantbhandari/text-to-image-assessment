<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Services\CreditService;
use App\Services\GenerationService;
use App\Services\PricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            // "Reuse" from History: re-open the form with every setting of an earlier generation.
            'prefill' => $this->prefillFrom($request),
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

    /**
     * Settings of one of the user's own generations, for ?reuse={id}. Null otherwise.
     *
     * @return array{prompt:string,size:string,quality:string,reference_images:array<int,string>}|null
     */
    private function prefillFrom(Request $request): ?array
    {
        $id = $request->integer('reuse');
        if (! $id) {
            return null;
        }

        $source = Generation::query()->where('user_id', $request->user()->id)->find($id);

        return $source ? [
            'prompt' => $source->prompt,
            'size' => $source->size,
            'quality' => $source->quality,
            'reference_images' => $source->reference_images ?? [],
        ] : null;
    }

    /**
     * Stream the image through our server as a file download. Browsers ignore the
     * `download` attribute for cross-origin URLs, so the proxy is what makes it work.
     */
    public function download(Request $request, Generation $generation): StreamedResponse
    {
        abort_unless($generation->user_id === $request->user()->id, 404);
        abort_unless($generation->image_url && (! $generation->image_expires_at || $generation->image_expires_at->isFuture()), 410, 'This image link has expired.');

        $image = Http::timeout(30)->get($generation->image_url);
        abort_unless($image->successful(), 502, 'Could not fetch the image.');

        return response()->streamDownload(
            fn () => print ($image->body()),
            "imagen-{$generation->id}.png",
            ['Content-Type' => $image->header('Content-Type') ?: 'image/png'],
        );
    }

    /**
     * Remove a finished generation from the user's history. It is soft-deleted:
     * its charge stays in the ledger and the admin report. In-flight ones can't be
     * deleted because a result (and a charge) is still on its way.
     */
    public function destroy(Request $request, Generation $generation): RedirectResponse
    {
        abort_unless($generation->user_id === $request->user()->id, 404);

        if (! $generation->isTerminal()) {
            return back()->withErrors(['generation' => 'This image is still being created. You can delete it once it finishes.']);
        }

        $generation->delete();

        return back();
    }
}
