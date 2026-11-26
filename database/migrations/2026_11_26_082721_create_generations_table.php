<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per image generation requested by a user.
     * All *_nano columns are integer nanodollars (1e-9 USD).
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            // Our own public id: sent to Pixelforge as Idempotency-Key and metadata,
            // which lets a webhook be matched even before we stored the provider id.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable()->unique(); // gen_...

            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 16);
            $table->json('reference_images')->nullable();

            $table->string('status', 16)->default('queued')->index(); // queued|processing|succeeded|failed

            $table->bigInteger('estimated_cost_nano');      // price shown up front (expected)
            $table->bigInteger('estimated_max_nano');       // worst case; reserved against the balance
            $table->bigInteger('provider_cost_nano')->nullable(); // what Pixelforge billed us
            $table->bigInteger('charged_nano')->nullable();       // what the user paid (cost + markup)

            $table->json('usage')->nullable();
            $table->json('rates')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('completed_at')->nullable()->index(); // UTC day = billing day
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
