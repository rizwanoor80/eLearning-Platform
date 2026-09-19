<?php

namespace App\Models;

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Services\Ledger\LedgerService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of the append-only money ledger (invariant #1). Rows are inserted
 * only by `LedgerService`; a model that is updated or deleted throws, and the
 * database refuses the same statements with a trigger. Amounts are signed
 * integer fils — a plain int here, not `Money`, because a leg is legitimately
 * negative.
 *
 * @property int $id
 * @property int|null $lesson_id
 * @property int|null $payout_id
 * @property int|null $dispute_id
 * @property LedgerAccount $account
 * @property int|null $tutor_profile_id
 * @property LedgerEntryType $type
 * @property int $amount
 * @property string|null $memo
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (LedgerEntry $entry): void {
            if (! LedgerService::isWriting()) {
                throw new LogicException('ledger_entries rows are inserted only by LedgerService.');
            }
        });

        static::updating(function (): void {
            throw new LogicException('ledger_entries is append-only: an entry cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('ledger_entries is append-only: an entry cannot be deleted.');
        });
    }

    /**
     * @return array<string, class-string|string>
     */
    protected function casts(): array
    {
        return [
            'account' => LedgerAccount::class,
            'type' => LedgerEntryType::class,
            'amount' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
