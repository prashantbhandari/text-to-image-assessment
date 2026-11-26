<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin users list (balances, spend, activity) and manual credit adjustments.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            // Totals include soft-deleted generations: deleting from history never changes the books.
            ->withSum(['generations as spent_nano' => fn ($q) => $q->withTrashed()->where('status', Generation::SUCCEEDED)], 'charged_nano')
            ->withCount([
                'generations as images' => fn ($q) => $q->withTrashed()->where('status', Generation::SUCCEEDED),
                'generations as failed' => fn ($q) => $q->withTrashed()->where('status', Generation::FAILED),
            ])
            // Case-insensitive on PostgreSQL and MariaDB/MySQL alike (ILIKE is Postgres-only).
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(email) LIKE ?', ['%'.mb_strtolower($search).'%'])))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_admin' => $u->is_admin,
                'balance_nano' => (int) $u->balance_nano,
                'spent_nano' => (int) $u->spent_nano,
                'images' => (int) $u->images,
                'failed' => (int) $u->failed,
                'joined' => $u->created_at->toDateString(),
            ]);

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => ['q' => $search],
            'totals' => [
                'users' => User::count(),
                'balance_nano' => (int) User::sum('balance_nano'),
            ],
        ]);
    }

    /** Give or take credits. Amount is USD with up to 2 decimals; negative takes credits away. */
    public function adjust(Request $request, User $user, CreditService $credits): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'regex:/^-?\d{1,6}(\.\d{1,2})?$/'],
            'note' => ['nullable', 'string', 'max:200'],
        ], ['amount.regex' => 'Enter an amount in dollars, like 5 or -2.50.']);

        // Dollars with <= 2 decimals -> cents (integer) -> nanodollars. No float drift.
        $cents = (int) round(((float) $data['amount']) * 100);
        if ($cents === 0) {
            return back()->withErrors(['amount' => 'Amount cannot be zero.']);
        }

        try {
            $credits->adjust($user, $cents * 10_000_000, $data['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back();
    }
}
