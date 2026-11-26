<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Pixelforge REST API.
 */
class PixelforgeClient
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(config('pixelforge.base_url'))
            ->withToken((string) config('pixelforge.api_key'))
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * Submit a generation. The Idempotency-Key makes retries safe: the same key
     * with the same params never creates (or bills) a second generation.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createGeneration(array $payload, string $idempotencyKey): Response
    {
        return $this->http()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $payload);
    }

    public function getGeneration(string $providerId): Response
    {
        return $this->http()->get('/generations/'.$providerId);
    }

    /** Public price list (no auth needed). */
    public function pricing(): Response
    {
        return Http::baseUrl(config('pixelforge.base_url'))->acceptJson()->timeout(10)->get('/pricing');
    }

    /** Provider-side usage report, used to reconcile our own cost records. */
    public function usage(string $startDate, string $endDate): Response
    {
        return $this->http()->get('/usage', ['start_date' => $startDate, 'end_date' => $endDate]);
    }
}
