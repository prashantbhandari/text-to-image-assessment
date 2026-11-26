<?php

namespace App\Services;

use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Builds the operator report. Shared by the admin page and the CSV export.
 *
 * Only SUCCEEDED generations count (failed ones cost nothing), bucketed by the UTC
 * day they completed: the same rule Pixelforge uses for GET /v1/usage. Soft-deleted
 * generations are included on purpose; deleting from a user's history must not
 * change the books.
 */
class AdminReportService
{
    /** Pixelforge refuses usage ranges longer than this. */
    public const MAX_DAYS = 92;

    public function __construct(private PixelforgeClient $client) {}

    /**
     * Clamp user input to a valid inclusive UTC date range (default: last 30 days).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(?string $from, ?string $to): array
    {
        $parse = function (?string $value, CarbonImmutable $fallback): CarbonImmutable {
            try {
                return $value ? CarbonImmutable::createFromFormat('Y-m-d', $value, 'UTC')->startOfDay() : $fallback;
            } catch (Throwable) {
                return $fallback;
            }
        };

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $end = $parse($to, $today);
        $start = $parse($from, $end->subDays(29));

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) >= self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        return [$start, $end];
    }

    /** Base query: billed generations completed inside the range. */
    private function billed(CarbonImmutable $start, CarbonImmutable $end)
    {
        return Generation::withTrashed()
            ->where('generations.status', Generation::SUCCEEDED)
            ->where('generations.completed_at', '>=', $start)
            ->where('generations.completed_at', '<', $end->addDay());
    }

    /**
     * Rows grouped by 'day', 'user' or 'day_user'.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(CarbonImmutable $start, CarbonImmutable $end, string $by): Collection
    {
        $sums = 'SUM(generations.charged_nano) as charged, SUM(generations.provider_cost_nano) as cost, COUNT(*) as generations';
        $q = $this->billed($start, $end);

        if ($by === 'day') {
            $q->selectRaw("DATE(generations.completed_at) as day, $sums")->groupBy('day')->orderByDesc('day');
        } else {
            $q->join('users', 'users.id', '=', 'generations.user_id');
            if ($by === 'user') {
                $q->selectRaw("users.id as user_id, users.name, users.email, $sums")
                    ->groupBy('users.id', 'users.name', 'users.email')->orderByDesc('charged');
            } else {
                $q->selectRaw("DATE(generations.completed_at) as day, users.id as user_id, users.name, users.email, $sums")
                    ->groupBy('day', 'users.id', 'users.name', 'users.email')->orderByDesc('day')->orderByDesc('charged');
            }
        }

        return $q->get()->map(fn ($r) => [
            'day' => $r->day ?? null,
            'user_id' => $r->user_id ?? null,
            'name' => $r->name ?? null,
            'email' => $r->email ?? null,
            'generations' => (int) $r->generations,
            'charged_nano' => (int) $r->charged,
            'cost_nano' => (int) $r->cost,
            'profit_nano' => (int) $r->charged - (int) $r->cost,
        ]);
    }

    /**
     * Totals plus a gap-free daily series (zero days included) for the chart.
     *
     * @param  Collection<int, array<string, mixed>>  $perDay
     * @return array{totals: array<string, int>, series: array<int, array<string, mixed>>}
     */
    public function summarise(Collection $perDay, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $byDay = $perDay->keyBy('day');
        $series = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $key = $d->toDateString();
            $series[] = $byDay[$key] ?? ['day' => $key, 'generations' => 0, 'charged_nano' => 0, 'cost_nano' => 0, 'profit_nano' => 0];
        }

        return [
            'totals' => [
                'charged_nano' => (int) $perDay->sum('charged_nano'),
                'cost_nano' => (int) $perDay->sum('cost_nano'),
                'profit_nano' => (int) $perDay->sum('profit_nano'),
                'generations' => (int) $perDay->sum('generations'),
            ],
            'series' => $series,
        ];
    }

    /**
     * Compare our per-day provider cost and generation count with Pixelforge's
     * GET /v1/usage. Returns null if Pixelforge can't be reached.
     *
     * @param  Collection<int, array<string, mixed>>  $perDay
     * @return array{rows: array<int, array<string, mixed>>, matches: bool, mismatches: int, theirs_total_nano: int}|null
     */
    public function reconcile(Collection $perDay, CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        $key = 'pixelforge.usage.'.$start->toDateString().'.'.$end->toDateString();

        try {
            $usage = Cache::remember($key, 20, function () use ($start, $end) {
                $r = $this->client->usage($start->toDateString(), $end->toDateString());

                return $r->successful() ? $r->json() : null;
            });
        } catch (Throwable) {
            return null;
        }

        if (! $usage || ! isset($usage['data'])) {
            return null;
        }

        $ours = $perDay->keyBy('day');
        $rows = [];
        $mismatches = 0;

        foreach ($usage['data'] as $d) {
            $mine = $ours[$d['date']] ?? null;
            $theirsNano = (int) str_replace('.', '', $d['cost_usd']); // "0.169080000" -> nanodollars, no floats
            $oursNano = (int) ($mine['cost_nano'] ?? 0);
            $match = $theirsNano === $oursNano && (int) $d['generations'] === (int) ($mine['generations'] ?? 0);
            $mismatches += $match ? 0 : 1;

            $rows[] = [
                'day' => $d['date'],
                'ours_nano' => $oursNano,
                'theirs_nano' => $theirsNano,
                'diff_nano' => $oursNano - $theirsNano,
                'ours_generations' => (int) ($mine['generations'] ?? 0),
                'theirs_generations' => (int) $d['generations'],
                'match' => $match,
            ];
        }

        return [
            'rows' => array_reverse($rows), // newest first
            'matches' => $mismatches === 0,
            'mismatches' => $mismatches,
            'theirs_total_nano' => (int) str_replace('.', '', $usage['total']['cost_usd'] ?? '0'),
        ];
    }
}
