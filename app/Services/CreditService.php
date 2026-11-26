<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Credit balance operations. Every movement writes an append-only ledger row
 * while the user row is locked, so the balance can never drift from the ledger.
 */
class CreditService
{
    /** Add credits (fake purchase, no payment involved). */
    public function purchase(User $user, int $amountNano): LedgerEntry
    {
        return DB::transaction(function () use ($user, $amountNano) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->balance_nano += $amountNano;
            $locked->save();

            return LedgerEntry::create([
                'user_id' => $locked->id,
                'type' => LedgerEntry::PURCHASE,
                'amount_nano' => $amountNano,
                'balance_after_nano' => $locked->balance_nano,
            ]);
        });
    }

    /**
     * Operator credit adjustment (positive = give, negative = take). The resulting
     * balance can never go below zero. Recorded in the ledger like every other movement.
     *
     * @throws \InvalidArgumentException when it would make the balance negative
     */
    public function adjust(User $user, int $amountNano, ?string $note = null): LedgerEntry
    {
        return DB::transaction(function () use ($user, $amountNano, $note) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($locked->balance_nano + $amountNano < 0) {
                throw new \InvalidArgumentException('That would make the balance negative.');
            }

            $locked->balance_nano += $amountNano;
            $locked->save();

            return LedgerEntry::create([
                'user_id' => $locked->id,
                'type' => LedgerEntry::ADJUSTMENT,
                'amount_nano' => $amountNano,
                'balance_after_nano' => $locked->balance_nano,
                'note' => $note,
            ]);
        });
    }

    /**
     * Charge a finished generation. Idempotent: the unique (generation_id, type)
     * index and the charged_nano check mean a generation is charged at most once.
     * Must be called inside a transaction that already locks the generation.
     */
    public function chargeForGeneration(Generation $generation, int $priceNano): void
    {
        if ($generation->charged_nano !== null) {
            return;
        }

        $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);
        $user->balance_nano -= $priceNano;
        $user->save();

        LedgerEntry::create([
            'user_id' => $user->id,
            'generation_id' => $generation->id,
            'type' => LedgerEntry::CHARGE,
            'amount_nano' => -$priceNano,
            'balance_after_nano' => $user->balance_nano,
        ]);

        $generation->charged_nano = $priceNano;
    }

    /**
     * Balance minus the worst-case price of every generation still in flight.
     * Only generations that can still be charged are reserved.
     */
    public function availableNano(User $user): int
    {
        $reserved = (int) Generation::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [Generation::QUEUED, Generation::PROCESSING])
            ->sum('estimated_max_nano');

        return (int) $user->fresh()->balance_nano - $reserved;
    }
}
