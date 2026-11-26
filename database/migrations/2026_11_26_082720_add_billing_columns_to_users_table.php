<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the credit balance (integer nanodollars) and the operator flag.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 1 USD = 1_000_000_000 nanodollars. Integers keep sub-cent costs exact.
            $table->bigInteger('balance_nano')->default(0);
            $table->boolean('is_admin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['balance_nano', 'is_admin']);
        });
    }
};
