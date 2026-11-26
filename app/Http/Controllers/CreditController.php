<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Services\CreditService;
use App\Services\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Credits page and the fake "Buy credits" action (no payment is taken).
 */
class CreditController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Credits', [
            'packs' => config('pixelforge.credit_packs'),
            'ledger' => LedgerEntry::query()
                ->where('user_id', $request->user()->id)
                ->latest('id')->limit(50)->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'type' => $e->type,
                    'amount_nano' => $e->amount_nano,
                    'balance_after_nano' => $e->balance_after_nano,
                    'created_at' => $e->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function purchase(Request $request, CreditService $credits): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', Rule::in(config('pixelforge.credit_packs'))],
        ]);

        $credits->purchase($request->user(), Money::usdToNano($data['amount']));

        return back();
    }
}
