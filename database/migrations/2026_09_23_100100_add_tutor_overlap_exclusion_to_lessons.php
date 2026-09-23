<?php

use App\Enums\LessonStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `lessons_tutor_slot_unique` (CP2) only catches two lessons sharing the exact
 * same `starts_at`. A tutor whose availability rules sit on different offsets
 * (e.g. one grid at :00, another at :30) can have two bookable slots that
 * genuinely overlap without sharing a `starts_at` — SlotCalculator's own
 * blocking rule treats this as overlap, but nothing enforced it in the
 * database once two different `starts_at` values are both committed. This
 * adds a real interval-overlap guard, additive to the existing index.
 *
 * `tsrange`, not `tstzrange`: `starts_at`/`ends_at` are
 * `timestamp without time zone`, and casting them to `timestamptz` inside the
 * index expression is STABLE (depends on session TimeZone), which Postgres
 * refuses inside a GiST index. Bounds are `[)` so a 09:00-10:00 lesson and a
 * 10:00-11:00 lesson for the same tutor can coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        $freeing = implode("', '", LessonStatus::freeingSlotValues());
        DB::statement(<<<SQL
            ALTER TABLE lessons ADD CONSTRAINT lessons_tutor_no_overlap
            EXCLUDE USING gist (
                tutor_profile_id WITH =,
                tsrange(starts_at, ends_at, '[)') WITH &&
            ) WHERE (status NOT IN ('{$freeing}'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE lessons DROP CONSTRAINT IF EXISTS lessons_tutor_no_overlap');
    }
};
