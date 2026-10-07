<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R179 (PLAN cycle 13 r1): a new `booking` settings group. `settings.group` was built by
 * `$table->enum('group', array_column(SettingGroup::cases(), 'value'))`, which Postgres freezes into
 * a CHECK constraint when that migration ran (the same trap CP8/R150 hit on `ledger_entries.type`),
 * so a database migrated earlier — local, rehearsal — rejects `booking`. Drop the constraint by its
 * Laravel-convention name (IF EXISTS: a database built after this enum change may already carry the
 * five-value list under the same name, which is dropped and re-added the same way) and re-add it
 * with a hardcoded literal list. The two existing booking keys move to the new group.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE settings DROP CONSTRAINT IF EXISTS settings_group_check');

        DB::unprepared("ALTER TABLE settings ADD CONSTRAINT settings_group_check CHECK (\"group\" IN ('platform', 'site', 'mail', 'features', 'booking'))");

        DB::table('settings')
            ->whereIn('key', ['booking_min_lead_hours', 'booking_max_days'])
            ->update(['group' => 'booking']);
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'booking')->update(['group' => 'platform']);

        DB::unprepared('ALTER TABLE settings DROP CONSTRAINT IF EXISTS settings_group_check');

        DB::unprepared("ALTER TABLE settings ADD CONSTRAINT settings_group_check CHECK (\"group\" IN ('platform', 'site', 'mail', 'features'))");
    }
};
