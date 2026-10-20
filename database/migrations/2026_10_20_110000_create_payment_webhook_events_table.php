<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 23b (R247): one row per verified gateway webhook event, unique on `(gateway_code, event_id)`, so a
 * replay of the same event id is a no-op. Only names, ids and times are kept — never the body, a
 * header or a card detail. Additive: nothing else changes (R252).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway_code', 16);
            $table->string('event_id');
            $table->string('type', 64);
            $table->string('object_ref')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');

            $table->unique(['gateway_code', 'event_id']);
            $table->index('object_ref');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
