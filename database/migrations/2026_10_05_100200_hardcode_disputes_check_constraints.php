<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CP8/R150 follow-up (advisor consult, 9b money-path review). The disputes
 * migration built its `reason`/`status` CHECK lists from
 * `array_column(DisputeReason::cases(), 'value')` at migrate time — the same
 * drift trap the ledger_entries `type` constraint hit (see the companion
 * migration in this batch). Migrations are forward-only (CLAUDE.md), so this
 * drops and re-adds both constraints with a hardcoded literal list, matching
 * `abuse_reports`' pattern, instead of editing the original file.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE disputes DROP CONSTRAINT disputes_reason_check');
        DB::unprepared('ALTER TABLE disputes DROP CONSTRAINT disputes_status_check');

        DB::unprepared("ALTER TABLE disputes ADD CONSTRAINT disputes_reason_check CHECK (reason IN ('no_show', 'quality', 'technical', 'other'))");
        DB::unprepared("ALTER TABLE disputes ADD CONSTRAINT disputes_status_check CHECK (status IN ('open', 'resolved'))");
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE disputes DROP CONSTRAINT disputes_reason_check');
        DB::unprepared('ALTER TABLE disputes DROP CONSTRAINT disputes_status_check');

        DB::unprepared("ALTER TABLE disputes ADD CONSTRAINT disputes_reason_check CHECK (reason IN ('no_show', 'quality', 'technical', 'other'))");
        DB::unprepared("ALTER TABLE disputes ADD CONSTRAINT disputes_status_check CHECK (status IN ('open', 'resolved'))");
    }
};
