<?php

namespace App\Models;

use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Support\Money;
use Database\Factories\PaymentRefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 24b (R260): one gateway refund attempt for a payment. Written before the gateway is asked (`requested`),
 * finished after it answers (`succeeded` or `failed`); a refund made at the gateway by someone else is
 * recorded as `external`. It is a record of what the gateway was asked and said: money still moves only
 * through `LedgerService`, and nothing here reverses or writes a ledger entry.
 *
 * @property int $id
 * @property int $payment_id
 * @property int|null $ledger_entry_id
 * @property PaymentRefundKind $kind
 * @property int $attempt
 * @property string|null $idempotency_key
 * @property Money $amount
 * @property string|null $gateway_refund_id
 * @property string|null $gateway_status
 * @property PaymentRefundStatus $status
 * @property string|null $failure_reason
 * @property Carbon $requested_at
 * @property Carbon|null $completed_at
 */
class PaymentRefund extends Model
{
    /** @use HasFactory<PaymentRefundFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, class-string|string>
     */
    protected function casts(): array
    {
        return [
            'amount' => Money::class,
            'kind' => PaymentRefundKind::class,
            'status' => PaymentRefundStatus::class,
            'attempt' => 'integer',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<LedgerEntry, $this>
     */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
