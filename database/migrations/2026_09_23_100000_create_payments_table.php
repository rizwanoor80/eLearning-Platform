<?php

use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATA_MODEL.md:137. `lesson_id` is unique: one lesson has at most one payment
 * row in v1 (a lesson never re-books after a failed capture — R56/BookLesson
 * creates a fresh lesson), and the uniqueness doubles as the idempotency
 * check when BookLesson writes this row right after a successful capture.
 *
 * `payment_method_id` is a plain nullable column without a foreign key, same
 * as `lessons.payment_method_id`: `payment_methods` arrives in CP5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payer_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->string('gateway');
            $table->string('gateway_ref')->nullable();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('AED');
            $table->enum('status', array_column(PaymentStatus::cases(), 'value'));
            $table->unsignedBigInteger('refunded_amount')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
