<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 23h (R253, R252). A payout moves a tutor's money out of the platform: `tutor −amount`, `bank +amount`.
 * `ledger_entries.account` was built as an enum, which Postgres froze into a CHECK constraint, so the new
 * `bank` account needs the constraint re-added (the same move as `add_release_reversal_to_ledger_entries_type_check`;
 * the ledger migration itself is not touched). The list is a literal, not built from the enum.
 *
 * Also adds, for the payout rows the ledger migration left no room for (existing rows all have a null `payout_id`, so
 * nothing is rewritten and the checks hold for them):
 *
 *  - the foreign key from `payout_id` to `payout_batch_lines`, and an index on it;
 *  - a partial unique index on `(payout_id, account)` for `payout` entries, so a line can never get two `tutor` legs or
 *    two `bank` legs whatever the application does: the database is the last guard against paying a line twice;
 *  - a CHECK that a `payout` entry always carries a `payout_id` and never a `lesson_id`, and nothing else carries a
 *    `payout_id`, so no entry can fall between the lesson check and the payout check of `ledger:verify`.
 *
 * `ADD CONSTRAINT` scans the table under an ACCESS EXCLUSIVE lock; the ledger is small enough for that to be instant.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_account_check');
        DB::unprepared("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_account_check CHECK (account IN ('gateway', 'escrow', 'tutor', 'platform', 'refund', 'bank'))");
        DB::unprepared('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_payout_id_foreign FOREIGN KEY (payout_id) REFERENCES payout_batch_lines (id) ON DELETE RESTRICT');
        DB::unprepared('CREATE INDEX ledger_entries_payout_id_index ON ledger_entries (payout_id) WHERE payout_id IS NOT NULL');
        DB::unprepared("CREATE UNIQUE INDEX ledger_entries_one_payout_leg_per_account ON ledger_entries (payout_id, account) WHERE type = 'payout'");
        DB::unprepared("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_payout_shape CHECK ((type = 'payout' AND payout_id IS NOT NULL AND lesson_id IS NULL) OR (type <> 'payout' AND payout_id IS NULL))");
    }

    public function down(): void
    {
        // Re-adding the old account CHECK would fail half way on a `bank` row, and the ledger is append-only: it cannot be
        // rolled back past money that has already left.
        if (DB::table('ledger_entries')->where('account', 'bank')->exists()) {
            throw new RuntimeException('Cannot roll back: the ledger has bank entries (paid payouts). The ledger is append-only.');
        }

        DB::unprepared('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_payout_shape');
        DB::unprepared('DROP INDEX ledger_entries_one_payout_leg_per_account');
        DB::unprepared('DROP INDEX IF EXISTS ledger_entries_payout_id_index');
        DB::unprepared('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_payout_id_foreign');
        DB::unprepared('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_account_check');
        DB::unprepared("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_account_check CHECK (account IN ('gateway', 'escrow', 'tutor', 'platform', 'refund'))");
    }
};
