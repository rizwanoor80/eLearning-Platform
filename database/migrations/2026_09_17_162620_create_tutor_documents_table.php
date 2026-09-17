<?php

use App\Enums\TutorDocumentStatus;
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
        Schema::create('tutor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('disk_path');
            $table->string('original_name');
            $table->enum('status', array_column(TutorDocumentStatus::cases(), 'value'))
                ->default(TutorDocumentStatus::Pending->value);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // A soft-deleted superseded upload must not block re-upload, so the
        // "one current document per (tutor, type)" rule is a partial index,
        // not a plain unique constraint Eloquent's SoftDeletes would collide with.
        DB::statement(
            'CREATE UNIQUE INDEX tutor_documents_tutor_profile_id_document_type_id_current_unique '.
            'ON tutor_documents (tutor_profile_id, document_type_id) WHERE deleted_at IS NULL',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tutor_documents_tutor_profile_id_document_type_id_current_unique');
        Schema::dropIfExists('tutor_documents');
    }
};
