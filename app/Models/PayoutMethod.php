<?php

namespace App\Models;

use App\Enums\PayoutMethodCode;
use Database\Factories\PayoutMethodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One payout-method registry entry (R249; table `payout_methods`). `countries` is a list of
 * upper-case ISO-3166 alpha-2 codes; an empty list means every country. The set of rows is fixed by
 * migration: an admin enables or disables a method and edits its name and countries.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property list<string> $countries
 * @property int $sort
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PayoutMethod extends Model
{
    /** @use HasFactory<PayoutMethodFactory> */
    use HasFactory;

    protected $fillable = ['code', 'name', 'is_active', 'countries', 'sort', 'updated_by'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'countries' => 'array',
        ];
    }

    /**
     * @param  Builder<PayoutMethod>  $query
     * @return Builder<PayoutMethod>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Active methods a tutor in `$country` may choose: those open to every country plus those that
     * list it. A tutor with no (or a malformed) country is offered only the open ones.
     *
     * @param  Builder<PayoutMethod>  $query
     * @return Builder<PayoutMethod>
     */
    public function scopeOfferedFor(Builder $query, ?string $country): Builder
    {
        $country = self::normaliseCountry($country);

        return $query->where('is_active', true)->where(function (Builder $query) use ($country): void {
            $query->whereRaw("countries = '[]'::jsonb");

            if ($country !== null) {
                $query->orWhereRaw('countries @> ?::jsonb', [json_encode([$country], JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public static function normaliseCountry(?string $country): ?string
    {
        $country = strtoupper(trim((string) $country));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function methodCode(): ?PayoutMethodCode
    {
        return PayoutMethodCode::tryFrom($this->code);
    }
}
