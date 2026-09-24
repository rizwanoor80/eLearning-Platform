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
 * One capture attempt's record for a lesson (DATA_MODEL.md:137). `lesson_id`
 * is unique — a lesson gets at most one payment row in v1, since a failed
 * capture expires the lesson rather than being retried on the same row
 * (R56/BookLesson always creates a fresh lesson to try again).
 *
 * @property int $id
 * @property int $lesson_id
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
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }
}
