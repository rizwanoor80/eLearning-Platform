<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 24b (R260, R265): one row per gateway refund attempt. Until now the outcome lived in
 * `payments.raw_response` (`refunds`, `refund_failures`, `unverified_refund`), which could not be queried,
 * locked or constrained. The table says what the platform asked the gateway for, what it answered, and
 * which ledger entry the refund follows (null for a capture the platform never held, and for a refund made
 * at the gateway by someone else).
 *
 * Why the indexes are partial: at most one live (not failed) attempt per ledger entry, and per payment for
 * an unverified capture, so two workers cannot both create a refund; a failed attempt does not block the
 * next one an admin re-queues (`attempt` + 1, with its own idempotency key). `(ledger_entry_id, attempt)`
 * and the unverified `(payment_id, attempt)` also make the data copy below safe to run twice.
 *
 * The copy turns the existing JSON into rows once. The JSON stays (additive, forward-only); the code stops
 * writing it. The idempotency keys are rebuilt exactly as 23d and R259 formed them, so a refund the old code
 * made is found by the same key on the gateway's side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $table->string('kind', 16);
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('idempotency_key')->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('gateway_refund_id')->nullable();
            $table->string('gateway_status', 32)->nullable();
            $table->string('status', 16);
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'status']);
        });

        DB::unprepared("ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_kind_check CHECK (kind IN ('ledger', 'unverified', 'external'))");
        DB::unprepared("ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_status_check CHECK (status IN ('requested', 'succeeded', 'failed'))");
        DB::unprepared('ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_amount_positive CHECK (amount > 0)');
        DB::unprepared("ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_ledger_has_entry CHECK ((kind = 'ledger') = (ledger_entry_id IS NOT NULL))");

        DB::unprepared("CREATE UNIQUE INDEX payment_refunds_one_live_per_entry ON payment_refunds (ledger_entry_id) WHERE ledger_entry_id IS NOT NULL AND status <> 'failed'");
        DB::unprepared('CREATE UNIQUE INDEX payment_refunds_entry_attempt ON payment_refunds (ledger_entry_id, attempt) WHERE ledger_entry_id IS NOT NULL');
        DB::unprepared("CREATE UNIQUE INDEX payment_refunds_one_live_unverified ON payment_refunds (payment_id) WHERE kind = 'unverified' AND status <> 'failed'");
        DB::unprepared("CREATE UNIQUE INDEX payment_refunds_unverified_attempt ON payment_refunds (payment_id, attempt) WHERE kind = 'unverified'");
        DB::unprepared('CREATE UNIQUE INDEX payment_refunds_gateway_refund_unique ON payment_refunds (payment_id, gateway_refund_id) WHERE gateway_refund_id IS NOT NULL');
        DB::unprepared('CREATE UNIQUE INDEX payment_refunds_idempotency_key_unique ON payment_refunds (idempotency_key) WHERE idempotency_key IS NOT NULL');

        $this->copyLedgerRefunds();
        $this->copyUnverifiedRefunds();
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }

    /**
     * `refunds.<entry id>` -> succeeded, `refund_failures.<entry id>` -> failed. `raw_response` is a `json`
     * column and its keys are text: every cast is guarded, and the join to `ledger_entries` (on the
     * payment's own lesson) means a stale key can never break the foreign key.
     */
    private function copyLedgerRefunds(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO payment_refunds (payment_id, ledger_entry_id, kind, attempt, idempotency_key, amount, gateway_refund_id, status, requested_at, completed_at, created_at, updated_at)
            SELECT p.id, e.id, 'ledger', 1, 'refund-' || p.gateway_ref || '-' || e.id,
                   greatest(1, coalesce(CASE WHEN r.value->>'amount_fils' ~ '^[0-9]{1,18}$' THEN (r.value->>'amount_fils')::bigint END, least(e.amount, p.amount))),
                   r.value->>'id', 'succeeded', coalesce(CASE WHEN r.value->>'at' IS NOT NULL THEN (r.value->>'at')::timestamptz END, now()),
                   coalesce(CASE WHEN r.value->>'at' IS NOT NULL THEN (r.value->>'at')::timestamptz END, now()), now(), now()
            FROM payments p
            CROSS JOIN LATERAL jsonb_each(CASE WHEN jsonb_typeof(p.raw_response::jsonb->'refunds') = 'object' THEN p.raw_response::jsonb->'refunds' ELSE '{}'::jsonb END) AS r(key, value)
            JOIN ledger_entries e ON e.id = CASE WHEN r.key ~ '^[0-9]{1,18}$' THEN r.key::bigint END AND e.lesson_id = p.lesson_id
            WHERE p.gateway_ref IS NOT NULL
            ON CONFLICT DO NOTHING
        SQL);

        DB::unprepared(<<<'SQL'
            INSERT INTO payment_refunds (payment_id, ledger_entry_id, kind, attempt, idempotency_key, amount, status, failure_reason, requested_at, completed_at, created_at, updated_at)
            SELECT p.id, e.id, 'ledger', 1, 'refund-' || p.gateway_ref || '-' || e.id, greatest(1, least(e.amount, p.amount)),
                   'failed', left(coalesce(r.value->>'code', 'unknown'), 500),
                   coalesce(CASE WHEN r.value->>'at' IS NOT NULL THEN (r.value->>'at')::timestamptz END, now()),
                   coalesce(CASE WHEN r.value->>'at' IS NOT NULL THEN (r.value->>'at')::timestamptz END, now()), now(), now()
            FROM payments p
            CROSS JOIN LATERAL jsonb_each(CASE WHEN jsonb_typeof(p.raw_response::jsonb->'refund_failures') = 'object' THEN p.raw_response::jsonb->'refund_failures' ELSE '{}'::jsonb END) AS r(key, value)
            JOIN ledger_entries e ON e.id = CASE WHEN r.key ~ '^[0-9]{1,18}$' THEN r.key::bigint END AND e.lesson_id = p.lesson_id
            WHERE p.gateway_ref IS NOT NULL
            ON CONFLICT DO NOTHING
        SQL);
    }

    /**
     * The R259 marker (`unverified_refund`): `requested`, `succeeded` or `refused` (-> failed). The "already
     * refunded in full" branch wrote a succeeded marker with no id and no amount, so both may be null.
     */
    private function copyUnverifiedRefunds(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO payment_refunds (payment_id, ledger_entry_id, kind, attempt, idempotency_key, amount, gateway_refund_id, status, failure_reason, requested_at, completed_at, created_at, updated_at)
            SELECT p.id, NULL, 'unverified', 1, 'unverified-' || p.gateway_ref,
                   greatest(1, coalesce(CASE WHEN m.marker->>'amount_fils' ~ '^[0-9]{1,18}$' THEN (m.marker->>'amount_fils')::bigint END, p.amount)),
                   m.marker->>'id',
                   CASE m.marker->>'status' WHEN 'succeeded' THEN 'succeeded' WHEN 'refused' THEN 'failed' ELSE 'requested' END,
                   CASE WHEN m.marker->>'status' = 'refused' THEN left(coalesce(m.marker->>'code', 'unknown'), 500) END,
                   coalesce(CASE WHEN m.marker->>'at' IS NOT NULL THEN (m.marker->>'at')::timestamptz END, now()),
                   CASE WHEN m.marker->>'status' IN ('succeeded', 'refused') THEN coalesce(CASE WHEN m.marker->>'at' IS NOT NULL THEN (m.marker->>'at')::timestamptz END, now()) END,
                   now(), now()
            FROM payments p
            CROSS JOIN LATERAL (SELECT CASE WHEN jsonb_typeof(p.raw_response::jsonb->'unverified_refund') = 'object' THEN p.raw_response::jsonb->'unverified_refund' END AS marker) AS m
            WHERE p.gateway_ref IS NOT NULL AND m.marker IS NOT NULL
            ON CONFLICT DO NOTHING
        SQL);
    }
};
