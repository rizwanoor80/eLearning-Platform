<?php

use App\Enums\LevelTier;
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
        Schema::create('price_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained()->restrictOnDelete();
            $table->enum('level_tier', array_column(LevelTier::cases(), 'value'));
            $table->integer('min_rate');
            $table->integer('max_rate');
            $table->date('effective_from');
            $table->timestamps();

            $table->unique(['curriculum_id', 'level_tier', 'effective_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_bands');
    }
};
