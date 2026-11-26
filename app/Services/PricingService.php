<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Computes what a generation will cost (estimate) and what it did cost (actual).
 *
 * Known up front: input text tokens (approx, +-15%), reference image tokens
 * (exact) and output tokens (exact). Unknown until completion: reasoning
 * tokens, which fall in a per-quality range. The estimate therefore comes
 * with an expected value (range midpoint) and a worst-case maximum.
 */
class PricingService
{
    public function __construct(private PixelforgeClient $client) {}

    /**
     * Current USD-per-1M-token rates (cached 10 min, falls back to config).
     *
     * @return array<string, string>
     */
    public function rates(): array
    {
        return Cache::remember('pixelforge.rates', 600, function () {
            try {
                $response = $this->client->pricing();
                if ($response->successful() && is_array($response->json('rates'))) {
                    return $response->json('rates');
                }
            } catch (Throwable) {
                // fall through to defaults
            }

            return config('pixelforge.default_rates');
        });
    }

    /**
     * Estimate the cost of a request, in nanodollars (provider cost AND user price).
     *
     * @param  array<string, string>|null  $rates
     * @return array{expected_nano:int, min_nano:int, max_nano:int, price_expected_nano:int, price_min_nano:int, price_max_nano:int, tokens:array<string,int>}
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImageCount, ?array $rates = null): array
    {
        $rates ??= $this->rates();
        $cfg = config('pixelforge');

        $chars = mb_strlen($prompt);
        $text = (int) ceil($chars / $cfg['chars_per_text_token']);
        $textMax = (int) ceil($text * (1 + $cfg['text_token_variance']));
        $textMin = (int) floor($text * (1 - $cfg['text_token_variance']));
        $image = $referenceImageCount * $cfg['tokens_per_reference_image'];
        $output = $cfg['output_tokens'][$quality][$size] ?? 0;
        $range = $cfg['reasoning_range'][$quality];
        $reasoningMid = intdiv($range['min'] + $range['max'], 2);

        $cost = fn (int $t, int $reasoning) => $this->cost([
            'input_text_tokens' => $t,
            'input_image_tokens' => $image,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning,
        ], $rates);

        $expected = $cost($text, $reasoningMid);
        $min = $cost($textMin, $range['min']);
        $max = $cost($textMax, $range['max']);

        return [
            'expected_nano' => $expected,
            'min_nano' => $min,
            'max_nano' => $max,
            'price_expected_nano' => Money::withMarkup($expected),
            'price_min_nano' => Money::withMarkup($min),
            'price_max_nano' => Money::withMarkup($max),
            'tokens' => ['text' => $text, 'image' => $image, 'output' => $output],
        ];
    }

    /**
     * Exact provider cost in nanodollars from token usage and rates.
     * Mirrors Pixelforge's formula: tokens x rate / 1M, never rounded.
     *
     * @param  array<string, int>  $usage
     * @param  array<string, string>  $rates
     */
    public function cost(array $usage, array $rates): int
    {
        $total = 0;
        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
            $total += (int) ($usage[$key] ?? 0) * Money::rateToNanoPerToken($rates[$key]);
        }

        return $total;
    }
}
