<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin visibility: how often each webhook event was delivered and what we did
     * with it, plus an optional note on ledger entries (admin credit adjustments).
     */
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->unsignedInteger('delivery_count')->default(1); // >1 means Pixelforge re-sent it
            $table->timestamp('last_delivery_at')->nullable();
            $table->string('outcome', 24)->nullable(); // applied | ignored | unmatched
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->string('note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn(['delivery_count', 'last_delivery_at', 'outcome']);
        });
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
