<?php

namespace App\Models;

use App\Enums\PayoutLineStatus;
use App\Enums\PayoutMethodCode;
use App\Support\Money;
use Database\Factories\PayoutBatchLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One tutor's payout inside a batch (23h, R251; table `payout_batch_lines`). `details` is the method's account details
 * as they were when the batch was generated (encrypted, hidden from serialisation): `account_name`, `iban`, `swift`
 * and `bank_name` for a bank transfer, `email` for every other method. Only `GeneratePayoutBatch` creates a line and only
 * `MarkPayoutLinePaid` / `MarkPayoutLineFailed` change its status; the ledger's `payout` entries point at it by id.
 * R274: when the tutor is erased `details` is set to null by `ErasePayoutLineDetails` (the only writer) and nothing else on the line changes.
 *
 * @property int $id
 * @property int $payout_batch_id
 * @property int $tutor_profile_id
 * @property string $payout_method
 * @property Money $amount
 * @property string $currency
 * @property string $reference
 * @property array<string, string>|null $details null once erased with the tutor (R274)
 * @property Carbon|null $details_erased_at
 * @property PayoutLineStatus $status
 * @property string|null $external_reference
 * @property Carbon|null $paid_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PayoutBatchLine extends Model
{
    /** @use HasFactory<PayoutBatchLineFactory> */
    use HasFactory;

    /** The one currency of v1 (no multi-currency); every line is written in it and `Money::format()` shows it. */
    public const string CURRENCY = 'AED';

    protected $guarded = [];

    protected $hidden = ['details'];

    /** What the file an admin pays from says; fixed the moment the line is written, so the money sent is the money recorded. */
    private const array FROZEN = ['payout_batch_id', 'tutor_profile_id', 'payout_method', 'amount', 'currency', 'reference', 'details'];

    protected static function booted(): void
    {
        static::updating(function (self $line): void {
            foreach (self::FROZEN as $column) {
                if ($line->isDirty($column)) {
                    throw new LogicException("A payout line's {$column} cannot be changed once it is written.");
                }
            }

            $was = $line->getOriginal('status');
            $was = $was instanceof PayoutLineStatus ? $was : PayoutLineStatus::tryFrom((string) $was);

            if ($line->isDirty('status') && $was !== PayoutLineStatus::Pending) {
                throw new LogicException('Only a pending payout line can be paid or failed.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A payout line cannot be deleted.');
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'amount' => Money::class,
            'details' => 'encrypted:array',
            'status' => PayoutLineStatus::class,
            'details_erased_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function method(): PayoutMethodCode
    {
        return PayoutMethodCode::from($this->payout_method);
    }

    /**
     * @return BelongsTo<PayoutBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    /**
     * @return BelongsTo<TutorProfile, $this>
     */
    public function tutorProfile(): BelongsTo
    {
        return $this->belongsTo(TutorProfile::class);
    }
}
