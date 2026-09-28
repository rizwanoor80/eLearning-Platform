<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CP8/R150 (advisor consult, 9b money-path review). `ledger_entries.type` was
 * built by the original migration as
 * `$table->enum('type', array_column(LedgerEntryType::cases(), 'value'))`,
 * which Postgres freezes into a CHECK constraint at the moment that migration
 * ran. `RefreshDatabase` rebuilds the schema from the *current* enum in
 * tests, which masked this: any database migrated before today (local,
 * rehearsal) still carries the original six-value list and rejects
 * `release_reversal`, the type `LedgerService::settle()` writes for a lesson
 * that was already released when its dispute opened.
 *
 * Verified before this migration, on local:
 *   ledger_entries_type_check: CHECK (((type)::text = ANY (ARRAY['hold',
 *   'release_tutor', 'release_commission', 'refund', 'goodwill', 'payout'])))
 *
 * Migrations are forward-only (CLAUDE.md): this drops that constraint by its
 * real name and re-adds it with a hardcoded literal list (not built from the
 * enum, so a future case addition can't silently repeat this) including
 * `release_reversal`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_type_check');

        DB::unprepared("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check CHECK (type IN ('hold', 'release_tutor', 'release_commission', 'refund', 'goodwill', 'payout', 'release_reversal'))");
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_type_check');

        DB::unprepared("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check CHECK (type IN ('hold', 'release_tutor', 'release_commission', 'refund', 'goodwill', 'payout'))");
    }
};
