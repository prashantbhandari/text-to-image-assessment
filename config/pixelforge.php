<?php

return [
    // Base URL of the Pixelforge API (no trailing slash).
    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    // Bearer API key for outgoing requests.
    'api_key' => env('PIXELFORGE_API_KEY'),

    // whsec_... secret used to verify the Pixelforge-Signature header.
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Max allowed clock skew (seconds) between signature timestamp and now.
    'webhook_tolerance' => 300,

    // Markup applied on top of Pixelforge's cost: 25 => cost * 1.25.
    'markup_percent' => 25,

    // Fallback rates (USD per 1M tokens) used if GET /v1/pricing is unreachable.
    'default_rates' => [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ],

    // Deterministic token facts from the docs.
    'chars_per_text_token' => 4,
    'tokens_per_reference_image' => 765,
    'text_token_variance' => 0.15,
    'max_reference_images' => 4,

    // Output tokens by [quality][size]; exact.
    'output_tokens' => [
        'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
        'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
        'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
    ],

    // Reasoning tokens are only known after completion; range by quality.
    'reasoning_range' => [
        'low' => ['min' => 0, 'max' => 500],
        'medium' => ['min' => 200, 'max' => 2000],
        'high' => ['min' => 1000, 'max' => 8000],
    ],

    // Fake "Buy credits" packs, in whole USD.
    'credit_packs' => [5, 10, 25, 50],
];
