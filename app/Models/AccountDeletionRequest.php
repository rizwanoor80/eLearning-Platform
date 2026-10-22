<?php

namespace App\Models;

use App\Enums\AccountDeletionRequestStatus;
use Database\Factories\AccountDeletionRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * R273: a parent's or tutor's request that an admin erase their account. It is a queue row, not an action:
 * only `EraseAccount` and `DeclineAccountDeletionRequest` move it out of `open`. `admin_note` is the admin's
 * free text to the user on a decline; it is mailed to them and never copied into an audit row or a log.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $requested_at
 * @property AccountDeletionRequestStatus $status
 * @property int|null $handled_by
 * @property Carbon|null $handled_at
 * @property string|null $admin_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AccountDeletionRequest extends Model
{
    /** @use HasFactory<AccountDeletionRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'requested_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountDeletionRequestStatus::class,
            'requested_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by')->withTrashed();
    }

    /**
     * @param  Builder<AccountDeletionRequest>  $query
     * @return Builder<AccountDeletionRequest>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', AccountDeletionRequestStatus::Open);
    }
}
