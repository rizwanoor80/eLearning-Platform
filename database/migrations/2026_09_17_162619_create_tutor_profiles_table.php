<?php

use App\Enums\TutorProfileStatus;
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
        Schema::create('tutor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('headline')->nullable();
            $table->text('bio')->nullable();
            $table->string('intro_video_url')->nullable();
            $table->integer('hourly_rate')->nullable();
            $table->enum('status', array_column(TutorProfileStatus::cases(), 'value'))
                ->default(TutorProfileStatus::Draft->value);
            $table->string('permit_number')->nullable();
            $table->date('permit_expires_at')->nullable();
            $table->timestamp('agreement_accepted_at')->nullable();
            $table->unsignedInteger('agreement_version')->nullable();
            $table->text('bank_name')->nullable();
            $table->text('bank_account_name')->nullable();
            $table->text('bank_iban')->nullable();
            $table->text('bank_swift')->nullable();
            $table->timestamp('bank_verified_at')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('lessons_completed')->default(0);
            $table->unsignedInteger('late_report_count_90d')->default(0);
            $table->unsignedInteger('strike_count_90d')->default(0);
            $table->text('review_note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tutor_profiles');
    }
};
