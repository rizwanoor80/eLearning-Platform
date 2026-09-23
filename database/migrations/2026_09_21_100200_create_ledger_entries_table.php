<?php

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only money ledger (R52, invariant #1). Only LedgerService inserts;
 * nothing updates or deletes — the model refuses, and so does the database (a
 * trigger), because a bug or a hand-run SQL statement is exactly what an
 * append-only ledger has to survive. Amounts are signed integer fils.
 *
 * `payout_id` and `dispute_id` are plain nullable columns without foreign keys:
 * `payouts` (CP5) and `disputes` (CP8) do not exist yet and add the constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payout_id')->nullable();
            $table->unsignedBigInteger('dispute_id')->nullable();
            $table->enum('account', array_column(LedgerAccount::cases(), 'value'));
            $table->foreignId('tutor_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('type', array_column(LedgerEntryType::cases(), 'value'));
            $table->bigInteger('amount');
            $table->string('memo')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['lesson_id', 'account']);
            $table->index('tutor_profile_id');
        });

        DB::unprepared("CREATE OR REPLACE FUNCTION ledger_entries_are_append_only() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'ledger_entries is append-only: % is not allowed', TG_OP; END; \$\$ LANGUAGE plpgsql");
        DB::unprepared('CREATE TRIGGER ledger_entries_no_update_delete BEFORE UPDATE OR DELETE ON ledger_entries FOR EACH ROW EXECUTE FUNCTION ledger_entries_are_append_only()');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_entries_are_append_only()');
    }
};
