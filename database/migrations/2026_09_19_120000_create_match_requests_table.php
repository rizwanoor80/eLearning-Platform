<?php

use App\Enums\BudgetTier;
use App\Enums\MatchRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('match_requests', function (Blueprint $table) {
            $table->id();
            // Both cascade: a hard-deleted account takes its learners and its
            // requests with it, in either order (no restrict mid-cascade).
            $table->foreignId('account_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            // The request's own snapshot: later edits to the learner never change it.
            $table->foreignId('curriculum_id')->constrained('curricula')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->string('year_group');
            $table->text('goals');
            $table->text('preferred_times')->nullable();
            $table->enum('budget_tier', array_column(BudgetTier::cases(), 'value'));
            $table->enum('status', array_column(MatchRequestStatus::cases(), 'value'))->default(MatchRequestStatus::Open->value);
            $table->json('suggested_tutor_ids')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suggested_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('account_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_requests');
    }
};
