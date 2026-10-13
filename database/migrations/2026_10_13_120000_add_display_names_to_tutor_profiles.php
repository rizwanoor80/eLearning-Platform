<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R195 (PLAN cycle 15 r1, ADR-027): a tutor may ask for a display name; an admin approves it before
 * anyone sees it. `display_name` is the approved name parents see (null = the first word of the account
 * name, R32). `display_name_requested` is the pending request: a non-null value IS the pending state, so
 * there is no status column (approve copies it to `display_name`, reject clears it; the rejection note
 * lives in the audit row and the mail). 30 characters is the plan's upper bound; the validator
 * (`App\Rules\DisplayNameRule`) enforces 2 to 30 code points before a value is ever stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table): void {
            $table->string('display_name', 30)->nullable()->after('headline');
            $table->string('display_name_requested', 30)->nullable()->after('display_name');
            $table->timestamp('display_name_requested_at')->nullable()->after('display_name_requested');
        });
    }

    public function down(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table): void {
            $table->dropColumn(['display_name', 'display_name_requested', 'display_name_requested_at']);
        });
    }
};
