<?php

namespace App\Models;

use App\Enums\CurriculumCode;
use Database\Factories\CurriculumFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property CurriculumCode $code
 * @property string $name
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Curriculum extends Model
{
    /** @use HasFactory<CurriculumFactory> */
    use HasFactory;

    protected $table = 'curricula';

    protected $fillable = ['code', 'name', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => CurriculumCode::class,
            'sort' => 'integer',
        ];
    }

    /**
     * @return HasMany<PriceBand, $this>
     */
    public function priceBands(): HasMany
    {
        return $this->hasMany(PriceBand::class);
    }
}
