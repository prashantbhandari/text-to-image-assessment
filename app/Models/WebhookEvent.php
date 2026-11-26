<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Pixelforge webhook event we have already accepted (dedupe record). */
class WebhookEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'last_delivery_at' => 'datetime'];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class, 'generation_provider_id', 'provider_id')->withTrashed();
    }
}
