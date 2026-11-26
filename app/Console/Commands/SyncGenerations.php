<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\GenerationStateService;
use App\Services\PixelforgeClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Polling fallback: if every webhook attempt for a generation failed, pull its
 * state directly. Uses the same state service as the webhook, so it is idempotent.
 */
class SyncGenerations extends Command
{
    protected $signature = 'generations:sync {--older-than=60 : Only poll generations older than N seconds}';

    protected $description = 'Poll Pixelforge for generations still queued/processing (webhook fallback)';

    public function handle(PixelforgeClient $client, GenerationStateService $state): int
    {
        $stale = Generation::query()
            ->whereIn('status', [Generation::QUEUED, Generation::PROCESSING])
            ->whereNotNull('provider_id')
            ->where('created_at', '<', now()->subSeconds((int) $this->option('older-than')))
            ->get();

        foreach ($stale as $generation) {
            try {
                $response = $client->getGeneration($generation->provider_id);
                if ($response->successful()) {
                    $state->apply($generation, $response->json());
                }
            } catch (Throwable $e) {
                $this->warn("{$generation->provider_id}: {$e->getMessage()}");
            }
        }

        $this->info("Checked {$stale->count()} generation(s).");

        return self::SUCCESS;
    }
}
