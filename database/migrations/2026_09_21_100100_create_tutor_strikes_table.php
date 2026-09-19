<?php

use App\Enums\StrikeType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_strikes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('type', array_column(StrikeType::cases(), 'value'));
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tutor_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_strikes');
    }
};
