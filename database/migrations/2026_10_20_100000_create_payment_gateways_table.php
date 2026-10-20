<?php

use App\Enums\PaymentGatewayCode;
use App\Enums\PaymentGatewayMode;
use App\Providers\PaymentGatewayServiceProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 23a (R246): the payment-gateway registry (DATA_MODEL `payment_gateways`, PRD §12, invariant 16).
 *
 * The rows are inserted here, not seeded: rehearsal is never re-seeded, and it needs a `fake` row
 * already active so booking keeps working there. `fake` starts active only where the fake gateway
 * is allowed (the same allow-list as ADR-016/ADR-022, not a `!== production` deny-list); `stripe`
 * starts inactive, in test mode, with no credentials. On production no row is active, so booking
 * shows the admin notice until an admin activates one. The table is created here, so both rows are
 * always new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('mode')->default(PaymentGatewayMode::Test->value);
            $table->text('credentials')->nullable();
            $table->boolean('supports_saved_cards')->default(false);
            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('create unique index payment_gateways_one_active on payment_gateways (is_active) where is_active');

        $now = now();

        DB::table('payment_gateways')->insert([
            [
                'code' => PaymentGatewayCode::Stripe->value,
                'name' => PaymentGatewayCode::Stripe->label(),
                'mode' => PaymentGatewayMode::Test->value,
                'supports_saved_cards' => true,
                'is_active' => false,
                'sort' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => PaymentGatewayCode::Fake->value,
                'name' => PaymentGatewayCode::Fake->label(),
                'mode' => PaymentGatewayMode::Test->value,
                'supports_saved_cards' => true,
                'is_active' => PaymentGatewayServiceProvider::fakeGatewayAllowed(),
                'sort' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
