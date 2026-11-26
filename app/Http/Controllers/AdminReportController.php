<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\PixelforgeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Operator report: credit spend (charged to users), provider cost (charged to
 * us by Pixelforge) and profit, per day and per user.
 *
 * Only succeeded generations count. They are bucketed by the UTC day they
 * completed, which is exactly how Pixelforge's /v1/usage buckets them, so the
 * provider-cost column is directly comparable.
 */
class AdminReportController extends Controller
{
    public function __invoke(PixelforgeClient $client): Response
    {
        $day = DB::raw('DATE(completed_at) as day');
        $sums = 'SUM(charged_nano) as charged, SUM(provider_cost_nano) as cost, COUNT(*) as generations';

        $perDay = Generation::query()->where('status', Generation::SUCCEEDED)
            ->selectRaw("DATE(completed_at) as day, $sums")
            ->groupBy('day')->orderByDesc('day')->get();

        $perUser = Generation::query()->where('generations.status', Generation::SUCCEEDED)
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->selectRaw("users.id as user_id, users.name, users.email, $sums")
            ->groupBy('users.id', 'users.name', 'users.email')->orderByDesc('charged')->get();

        $perDayUser = Generation::query()->where('generations.status', Generation::SUCCEEDED)
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->selectRaw("DATE(completed_at) as day, users.id as user_id, users.name, users.email, $sums")
            ->groupBy('day', 'users.id', 'users.name', 'users.email')
            ->orderByDesc('day')->orderByDesc('charged')->get();

        $row = fn ($r) => [
            'day' => $r->day ?? null,
            'user_id' => $r->user_id ?? null,
            'name' => $r->name ?? null,
            'email' => $r->email ?? null,
            'generations' => (int) $r->generations,
            'charged_nano' => (int) $r->charged,
            'cost_nano' => (int) $r->cost,
            'profit_nano' => (int) $r->charged - (int) $r->cost,
        ];

        return Inertia::render('Admin/Report', [
            'per_day' => $perDay->map($row)->values(),
            'per_user' => $perUser->map($row)->values(),
            'per_day_user' => $perDayUser->map($row)->values(),
            'totals' => [
                'charged_nano' => (int) $perDay->sum('charged'),
                'cost_nano' => (int) $perDay->sum('cost'),
                'profit_nano' => (int) $perDay->sum('charged') - (int) $perDay->sum('cost'),
                'generations' => (int) $perDay->sum('generations'),
            ],
            'reconciliation' => $this->reconcile($client, $perDay->pluck('cost', 'day')->all()),
        ]);
    }

    /**
     * Compare our per-day provider cost with Pixelforge's GET /v1/usage.
     * Returns null when the API is unreachable.
     *
     * @param  array<string, int|string>  $ours
     * @return array<string, mixed>|null
     */
    private function reconcile(PixelforgeClient $client, array $ours): ?array
    {
        if (! $ours) {
            return ['days' => [], 'total_nano' => 0, 'matches' => true];
        }

        $start = min(array_keys($ours));
        $end = max(array_keys($ours));

        try {
            $usage = Cache::remember("pixelforge.usage.$start.$end", 30, function () use ($client, $start, $end) {
                $r = $client->usage($start, $end);

                return $r->successful() ? $r->json() : null;
            });
        } catch (Throwable) {
            return null;
        }

        if (! $usage) {
            return null;
        }

        $days = [];
        $matches = true;
        foreach ($usage['data'] as $d) {
            // "0.169080000" -> nanodollars, exactly (string math, no floats).
            $theirs = (int) str_replace('.', '', $d['cost_usd']);
            $mine = (int) ($ours[$d['date']] ?? 0);
            $days[$d['date']] = ['provider_nano' => $theirs, 'match' => $theirs === $mine];
            $matches = $matches && $theirs === $mine;
        }

        return ['days' => $days, 'total_nano' => (int) str_replace('.', '', $usage['total']['cost_usd']), 'matches' => $matches];
    }
}
