<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('learners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('display_name');
            $table->boolean('is_minor')->default(true);
            // Nullable only so an adult student's self-learner can exist from
            // registration; parent-created learners always carry both (form request).
            $table->string('year_group')->nullable();
            $table->foreignId('curriculum_id')->nullable()->constrained('curricula')->restrictOnDelete();
            $table->string('school')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('account_user_id');
        });

        // One self-learner (is_minor = false) per account.
        DB::statement('CREATE UNIQUE INDEX learners_one_self_per_account ON learners (account_user_id) WHERE is_minor = false AND deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('learners');
    }
};
