// Money helpers. The backend stores integer nanodollars (1 USD = 1e9 nano).

export const NANO_PER_USD = 1_000_000_000;

/**
 * Format nanodollars as USD. Stored values stay exact; this only limits what is
 * displayed. Rounds to `maxDecimals` (default 4), trims trailing zeros, and always
 * shows at least two decimals: $5.00, $0.05, $0.0634. Pass 9 for exact output.
 */
export function formatNano(nano, maxDecimals = 4) {
    if (nano === null || nano === undefined) return '—';
    const sign = nano < 0 ? '-' : '';
    const unit = 10 ** (9 - maxDecimals);
    const rounded = Math.round(Math.abs(nano) / unit) * unit;
    const whole = Math.floor(rounded / NANO_PER_USD);
    let frac = String(rounded % NANO_PER_USD).padStart(9, '0').slice(0, maxDecimals).replace(/0+$/, '');
    frac = frac.padEnd(2, '0');
    return `${sign}$${whole}.${frac}`;
}

/** Fixed 4-decimal USD, for tables where alignment matters more than exactness. */
export function formatNanoFixed(nano, digits = 4) {
    if (nano === null || nano === undefined) return '—';
    return `${nano < 0 ? '-' : ''}$${(Math.abs(nano) / NANO_PER_USD).toFixed(digits)}`;
}

/** Rate string ("12.50" USD per 1M tokens) -> nanodollars per token. */
const ratePerToken = (r) => Math.round(parseFloat(r) * 1000);

/**
 * Mirror of PricingService::estimate on the server (the server is authoritative).
 * Returns the user-facing price: expected value and min/max range.
 */
export function estimatePrice(p, { prompt, size, quality, referenceCount }) {
    const text = Math.ceil([...prompt].length / p.chars_per_text_token);
    const image = referenceCount * p.tokens_per_reference_image;
    const output = p.output_tokens[quality][size];
    const range = p.reasoning_range[quality];
    const r = p.rates;
    const cost = (t, reasoning) =>
        t * ratePerToken(r.input_text_tokens) +
        image * ratePerToken(r.input_image_tokens) +
        output * ratePerToken(r.output_tokens) +
        reasoning * ratePerToken(r.reasoning_tokens);
    const markup = (c) => Math.floor((c * (100 + p.markup_percent) + 50) / 100);

    return {
        expected: markup(cost(text, Math.floor((range.min + range.max) / 2))),
        min: markup(cost(Math.floor(text * (1 - p.text_token_variance)), range.min)),
        max: markup(cost(Math.ceil(text * (1 + p.text_token_variance)), range.max)),
    };
}
