<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA_MODEL v1.7 (CP7 8b, R133/R134): one conversation per account/tutor pair, and its messages.
 * A message is stored already masked (R134) and never edited or deleted; only `read_at` may change,
 * and a trigger enforces that the way the ledger's does. Existing pairs are backfilled: any pair that
 * has a lesson which was ever confirmed or reserved gets a conversation, whatever became of the
 * lesson, and `first_lesson_completed_at` is the pair's earliest `lessons.completed_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('tutor_profile_id')->constrained()->restrictOnDelete();
            $table->timestamp('first_lesson_completed_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['account_user_id', 'tutor_profile_id']);
            $table->index('tutor_profile_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->boolean('body_masked')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION messages_are_append_only() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'messages is append-only: DELETE is not allowed';
    END IF;
    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.conversation_id IS DISTINCT FROM OLD.conversation_id
        OR NEW.sender_user_id IS DISTINCT FROM OLD.sender_user_id
        OR NEW.body IS DISTINCT FROM OLD.body
        OR NEW.body_masked IS DISTINCT FROM OLD.body_masked
        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'messages is append-only: only read_at may change';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER messages_no_edit_delete BEFORE UPDATE OR DELETE ON messages FOR EACH ROW EXECUTE FUNCTION messages_are_append_only()');

        $this->backfill();
    }

    /**
     * Existing pairs get a conversation. A `pending_payment` or `expired` lesson never opened one (R133), and a
     * single booking's `cancelled_payment_failed` came from `pending_payment`; a weekly one came from
     * `reserved`, which did. Idempotent, and public so a test can run it against seeded lessons.
     */
    public function backfill(): void
    {
        DB::statement(<<<'SQL'
INSERT INTO conversations (account_user_id, tutor_profile_id, first_lesson_completed_at, created_at, updated_at)
SELECT learners.account_user_id, lessons.tutor_profile_id, MIN(lessons.completed_at), NOW(), NOW()
FROM lessons
JOIN learners ON learners.id = lessons.learner_id
WHERE lessons.status NOT IN ('pending_payment', 'expired')
  AND (lessons.status <> 'cancelled_payment_failed' OR lessons.recurring_slot_id IS NOT NULL)
GROUP BY learners.account_user_id, lessons.tutor_profile_id
ON CONFLICT (account_user_id, tutor_profile_id) DO NOTHING
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS messages_no_edit_delete ON messages');
        Schema::dropIfExists('messages');
        DB::unprepared('DROP FUNCTION IF EXISTS messages_are_append_only()');
        Schema::dropIfExists('conversations');
    }
};
