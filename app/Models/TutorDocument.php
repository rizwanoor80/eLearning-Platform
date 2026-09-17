<?php

namespace App\Models;

use App\Enums\TutorDocumentStatus;
use Database\Factories\TutorDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tutor_profile_id
 * @property int $document_type_id
 * @property string $disk_path
 * @property string $original_name
 * @property TutorDocumentStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class TutorDocument extends Model
{
    /** @use HasFactory<TutorDocumentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['tutor_profile_id', 'document_type_id', 'disk_path', 'original_name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TutorDocumentStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TutorProfile, $this>
     */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
