<?php

namespace App\Models;

use Database\Factories\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $description
 * @property bool $required
 * @property bool $active
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentType extends Model
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory;

    /**
     * The type that holds a tutor's permit scan; the permit number and date are
     * fields on the profile. Code, not id, so a re-seeded database still finds it;
     * the code is immutable in the admin form so this cannot silently break.
     */
    public const PERMIT_CODE = 'permit';

    protected $fillable = ['code', 'name', 'description', 'required', 'active', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @param  Builder<DocumentType>  $query
     * @return Builder<DocumentType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @return HasMany<TutorDocument, $this>
     */
    public function tutorDocuments(): HasMany
    {
        return $this->hasMany(TutorDocument::class);
    }
}
