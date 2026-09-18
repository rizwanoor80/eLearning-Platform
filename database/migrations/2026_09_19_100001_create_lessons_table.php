<?php

use App\Enums\LessonStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal lessons table for SlotCalculator (CP2). CP3 extends it with
 * `Schema::table` — learner, price, policy, room and state columns — and does
 * not replace it. Nothing in CP2 changes `status` after creation.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->constrained()->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->enum('status', array_column(LessonStatus::cases(), 'value'));
            $table->timestamps();

            $table->index(['tutor_profile_id', 'starts_at']);
            $table->index('status');
        });

        $freeing = implode("', '", LessonStatus::freeingSlotValues());
        DB::statement("CREATE UNIQUE INDEX lessons_tutor_slot_unique ON lessons (tutor_profile_id, starts_at) WHERE status NOT IN ('{$freeing}')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
