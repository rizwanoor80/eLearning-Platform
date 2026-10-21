<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 24c (R261): a parent can remove the saved card. The row is kept, stamped `removed_at`, because payments and
 * lessons point at it by foreign key and their history must stay readable. "One card per account" becomes "one
 * live card per account": the plain unique index on `account_user_id` is replaced by a partial one over the rows
 * not removed, so a parent can remove a card and add another while the old row stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('last_failed_at');
            $table->dropUnique('payment_methods_account_user_id_unique');
        });

        DB::statement('CREATE UNIQUE INDEX payment_methods_one_live_per_account ON payment_methods (account_user_id) WHERE removed_at IS NULL');
        // Lookups by account read the live card, and the account's latest row for the gateway customer.
        DB::statement('CREATE INDEX payment_methods_account_user_id_index ON payment_methods (account_user_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payment_methods_account_user_id_index');
        DB::statement('DROP INDEX IF EXISTS payment_methods_one_live_per_account');

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('removed_at');
            $table->unique('account_user_id');
        });
    }
};
