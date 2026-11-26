<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A Pixelforge webhook event we have already accepted (dedupe record). */
class WebhookEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
