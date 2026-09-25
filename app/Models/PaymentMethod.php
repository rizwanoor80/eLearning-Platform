<?php

namespace App\Models;

use App\Enums\PaymentMethodStatus;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The account holder's saved card (DATA_MODEL `payment_methods`): gateway token, brand, last
 * four and expiry — never a card number (invariant 15). One per account in v1.
 *
 * @property int $id
 * @property int $account_user_id
 * @property string $gateway
 * @property string|null $gateway_customer_ref
 * @property string $gateway_token
 * @property string $brand
 * @property string $last4
 * @property int $exp_month
 * @property int $exp_year
 * @property PaymentMethodStatus $status
 * @property Carbon|null $last_failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * The token is a credential-grade value: never serialised.
     *
     * @var list<string>
     */
    protected $hidden = ['gateway_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'status' => PaymentMethodStatus::class,
            'last_failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_user_id')->withTrashed();
    }
}
