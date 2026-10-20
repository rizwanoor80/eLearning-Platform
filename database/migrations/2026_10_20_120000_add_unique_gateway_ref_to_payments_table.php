<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 23b (R247): one gateway charge can back one payment. `BookLesson` writes `gateway_ref` only after
 * `capture()` returns, so two concurrent bookings naming one confirmed PaymentIntent would both pass
 * a read-first check; the second one's update fails on this index instead and its lesson expires.
 * Partial, because a `pending` row has no reference yet. Additive (R252).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX payments_gateway_ref_unique ON payments (gateway, gateway_ref) WHERE gateway_ref IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_gateway_ref_unique');
    }
};
