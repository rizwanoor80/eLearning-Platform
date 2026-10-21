<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 25a (R266): a Stripe customer, card token or PaymentIntent id exists in one Stripe mode only, so the row that
 * stores one also records the mode it was made in (`test` | `live`). Every Stripe row that exists today was made
 * against the test account (live has never been switched on), so those are backfilled `test`; other gateways
 * keep null, which means "no mode". Additive: nothing existing is rewritten but the backfill, and a CHECK then requires
 * a mode on every Stripe row (COALESCE, because a CHECK passes on NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway_mode', 8)->nullable()->after('gateway_ref');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('gateway_mode', 8)->nullable()->after('gateway_token');
        });

        DB::table('payments')->where('gateway', 'stripe')->update(['gateway_mode' => 'test']);
        DB::table('payment_methods')->where('gateway', 'stripe')->update(['gateway_mode' => 'test']);

        // After the backfill, so a writer that forgets the mode fails loudly instead of leaving a null that
        // would be read as `test` once live is on (a live refund would then be refused).
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_stripe_has_mode CHECK (gateway <> 'stripe' OR COALESCE(gateway_mode IN ('test', 'live'), false))");
        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_stripe_has_mode CHECK (gateway <> 'stripe' OR COALESCE(gateway_mode IN ('test', 'live'), false))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_stripe_has_mode');
        DB::statement('ALTER TABLE payment_methods DROP CONSTRAINT IF EXISTS payment_methods_stripe_has_mode');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('gateway_mode');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('gateway_mode');
        });
    }
};
