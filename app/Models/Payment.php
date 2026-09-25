<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Support\Money;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One charge attempt's record for a lesson (DATA_MODEL.md:137). A booked lesson has one
 * attempt (a declined capture expires it; R56/BookLesson creates a fresh lesson to try again);
 * a weekly lesson can have several (R101): `(lesson_id, attempt_no)` is unique, and a partial
 * unique index allows only one row per lesson whose status is not `failed`.
 *
 * @property int $id
 * @property int $lesson_id
 * @property int $attempt_no
 * @property int $payer_user_id
 * @property int|null $payment_method_id
 * @property string $gateway
 * @property string|null $gateway_ref
 * @property Money $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property Money|null $refunded_amount
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $raw_response
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, class-string|string>
     */
    protected function casts(): array
    {
        return [
            'amount' => Money::class,
            'refunded_amount' => Money::class,
            'status' => PaymentStatus::class,
            'raw_response' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Includes an anonymised (soft-deleted) payer (R54): payment history
     * must outlive the account (invariant #1's "money moves only through
     * `LedgerService`" applies to the ledger, but a payment row referencing
     * a deleted payer must still resolve for audit purposes).
     *
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id')->withTrashed();
    }
}
