<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R273 (26a): the admin queue of "delete my account" requests from parents and tutors. A request never erases
 * anything; an admin erases (`AnonymizeUser`, unchanged) or declines with a note. `users` rows are soft-deleted,
 * never removed, so `restrictOnDelete()` needs no cascade handling. One open request per user is a partial unique
 * index, the same way the database enforces other "at most one live row" rules here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->string('status')->default('open');
            $table->foreignId('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'requested_at']);
            $table->index('user_id');
        });

        DB::unprepared("ALTER TABLE account_deletion_requests ADD CONSTRAINT account_deletion_requests_status_check CHECK (status IN ('open', 'erased', 'declined'))");
        DB::unprepared("CREATE UNIQUE INDEX account_deletion_requests_one_open_per_user ON account_deletion_requests (user_id) WHERE status = 'open'");
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
    }
};
