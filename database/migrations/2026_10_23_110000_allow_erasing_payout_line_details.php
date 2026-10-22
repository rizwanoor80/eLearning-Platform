<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R274 (26b): when a tutor is erased, the encrypted copy of their payout details on a payout line is erased too, while
 * amount, method, currency, reference, external reference and paid date stay. `details` becomes nullable and
 * `details_erased_at` records when. A line is "erased" exactly when both agree (CHECK). The one writer is
 * `ErasePayoutLineDetails`; a trigger makes the database itself refuse any other change to a line's details that is
 * not "set to null", so the frozen copy of a live line cannot be rewritten even by a query that bypasses the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE payout_batch_lines ALTER COLUMN details DROP NOT NULL');
        DB::unprepared('ALTER TABLE payout_batch_lines ADD COLUMN details_erased_at timestamp(0) without time zone NULL');
        DB::unprepared('ALTER TABLE payout_batch_lines ADD CONSTRAINT payout_batch_lines_details_erased_consistent CHECK ((details IS NULL) = (details_erased_at IS NOT NULL))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payout_batch_lines_details_only_erase() RETURNS trigger AS $$
            BEGIN
                IF NEW.details IS DISTINCT FROM OLD.details AND NEW.details IS NOT NULL THEN
                    RAISE EXCEPTION 'A payout line''s details can only be erased, not rewritten.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER payout_batch_lines_details_only_erase BEFORE UPDATE ON payout_batch_lines FOR EACH ROW EXECUTE FUNCTION payout_batch_lines_details_only_erase()');
    }
};
