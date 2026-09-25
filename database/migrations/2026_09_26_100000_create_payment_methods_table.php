<?php

use App\Enums\PaymentMethodStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CP4 (4a): the saved card (DATA_MODEL `payment_methods`, R100). One per account in v1.
 * Token, brand, last four and expiry only — never a card number (invariant 15).
 *
 * Also adds the foreign key `lessons.payment_method_id` deliberately left off in CP3
 * (its column was a plain nullable id until this table existed). `payments.payment_method_id`
 * gets its constraint in 4e, whose migration restructures `payments`. Both tables are empty on
 * every server (checked 2026-09-25), so the constraint needs no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('gateway');
            $table->string('gateway_customer_ref')->nullable();
            $table->string('gateway_token');
            $table->string('brand');
            $table->string('last4', 4);
            $table->unsignedTinyInteger('exp_month');
            $table->unsignedSmallInteger('exp_year');
            $table->enum('status', array_column(PaymentMethodStatus::cases(), 'value'))->default(PaymentMethodStatus::Active->value);
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
        });

        Schema::dropIfExists('payment_methods');
    }
};
