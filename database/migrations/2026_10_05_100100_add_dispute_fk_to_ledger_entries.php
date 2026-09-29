<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `disputes` now exists (this cycle) — add the FK the ledger_entries migration's
 * own comment invited: "`dispute_id`... `disputes` (CP8) do not exist yet and
 * add the constraint[]." `restrictOnDelete()` matches every other FK on this
 * table; `ledger_entries` is append-only (a trigger already refuses UPDATE/
 * DELETE on the table itself), so this can never cascade a delete in practice —
 * restrict is the honest declaration of that, not a load-bearing guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreign('dispute_id')->references('id')->on('disputes')->restrictOnDelete();
            $table->index('dispute_id');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['dispute_id']);
            $table->dropIndex(['dispute_id']);
        });
    }
};
