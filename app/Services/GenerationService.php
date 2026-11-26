<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates a generation, checks affordability, and submits it to Pixelforge.
 */
class GenerationService
{
    public function __construct(
        private PixelforgeClient $client,
        private PricingService $pricing,
        private CreditService $credits,
        private GenerationStateService $state,
    ) {}

    /**
     * @param  array{prompt:string,size:string,quality:string,reference_images?:array<int,string>}  $data
     */
    public function create(User $user, array $data): Generation
    {
        $refs = array_values(array_filter($data['reference_images'] ?? []));
        $estimate = $this->pricing->estimate($data['prompt'], $data['size'], $data['quality'], count($refs));

        // Block when the user can't cover the worst case (after reserving in-flight jobs).
        if ($this->credits->availableNano($user) < $estimate['price_max_nano']) {
            throw ValidationException::withMessages([
                'prompt' => 'Not enough credits for this generation. Buy more credits and try again.',
            ]);
        }

        $generation = Generation::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_images' => $refs,
            'status' => Generation::QUEUED,
            'estimated_cost_nano' => $estimate['price_expected_nano'],
            'estimated_max_nano' => $estimate['price_max_nano'],
        ]);

        $this->submit($generation, $refs);

        return $generation->fresh();
    }

    /** @param array<int, string> $refs */
    private function submit(Generation $generation, array $refs): void
    {
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'metadata' => ['generation_uuid' => $generation->uuid],
        ];
        if ($refs) {
            $payload['reference_images'] = $refs;
        }

        // Same Idempotency-Key on every attempt: a retry can never create a second generation.
        $response = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $this->client->createGeneration($payload, $generation->uuid);
            } catch (Throwable) {
                $response = null;
                usleep(300_000);

                continue;
            }
            if ($response->status() === 503) {
                usleep(500_000 * $attempt);

                continue;
            }
            break;
        }

        if ($response?->successful()) {
            // A webhook may already have advanced the row; apply() never moves it backwards.
            $this->state->apply($generation, $response->json());

            return;
        }

        $error = $response?->json('error') ?? [];
        $code = $error['code'] ?? 'unavailable';
        $message = match ($code) {
            'content_policy_violation' => 'This prompt was rejected by the content policy.',
            'concurrency_limit_exceeded', 'rate_limit_exceeded', 'daily_limit_exceeded' => 'Pixelforge is busy right now. Please try again in a moment.',
            default => $error['message'] ?? 'Could not reach Pixelforge. Please try again.',
        };
        $this->state->failLocally($generation, 'local_'.$code, $message);
    }
}
