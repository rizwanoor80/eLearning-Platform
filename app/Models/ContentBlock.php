<?php

namespace App\Models;

use Database\Factories\ContentBlockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An admin-editable piece of site copy: the four homepage blocks (`home_*`) and
 * the match-request budget labels (`match_budget_*`, R35). The set of keys is
 * fixed by the code (seeded, never created in the admin); the admin only changes
 * the body.
 *
 * @property int $id
 * @property string $key
 * @property string $body
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContentBlock extends Model
{
    /** @use HasFactory<ContentBlockFactory> */
    use HasFactory;

    public const HERO_TITLE = 'home_hero_title';

    public const HERO_TEXT = 'home_hero_text';

    public const HOW_IT_WORKS = 'home_how_it_works';

    public const FAQ = 'home_faq';

    protected $fillable = ['key', 'body', 'updated_by'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
