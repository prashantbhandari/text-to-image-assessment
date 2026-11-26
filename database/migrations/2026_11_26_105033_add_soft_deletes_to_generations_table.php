<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Users "delete" generations from their history by soft-deleting them.
     * The row (and its charge) must survive for the ledger and the admin report.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
