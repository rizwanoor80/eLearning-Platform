<?php

namespace App\Models;

use App\Enums\LevelTier;
use App\Support\Money;
use Database\Factories\PriceBandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $curriculum_id
 * @property LevelTier $level_tier
 * @property Money $min_rate
 * @property Money $max_rate
 * @property Carbon $effective_from
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PriceBand extends Model
{
    /** @use HasFactory<PriceBandFactory> */
    use HasFactory;

    protected $fillable = ['curriculum_id', 'level_tier', 'min_rate', 'max_rate', 'effective_from'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level_tier' => LevelTier::class,
            'min_rate' => Money::class,
            'max_rate' => Money::class,
            'effective_from' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Curriculum, $this>
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }
}
