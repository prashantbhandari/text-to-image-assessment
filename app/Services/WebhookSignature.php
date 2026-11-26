<?php

namespace App\Services;

/**
 * Verifies the Pixelforge-Signature header: "t=<unix>,v1=<hex>".
 * v1 = HMAC-SHA256(secret, "<t>.<raw body>"), hex-encoded.
 */
class WebhookSignature
{
    public function verify(string $rawBody, ?string $header, string $secret, int $tolerance, ?int $now = null): bool
    {
        if (! $header || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }

        if (! $timestamp || ! ctype_digit($timestamp) || ! $signatures) {
            return false;
        }

        // Reject replays of old captured requests.
        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) { // constant-time compare
                return true;
            }
        }

        return false;
    }
}
