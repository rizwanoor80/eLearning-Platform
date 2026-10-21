<?php

namespace App\Models;

use App\Enums\PaymentGatewayMode;
use App\Enums\PaymentMethodStatus;
use App\Services\Payments\PaymentGatewayManager;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The account holder's saved card (DATA_MODEL `payment_methods`): gateway token, brand, last
 * four and expiry — never a card number (invariant 15). One live card per account in v1; a removed card keeps
 * its row (`removed_at`) because payments and lessons point at it.
 *
 * @property int $id
 * @property int $account_user_id
 * @property string $gateway
 * @property string|null $gateway_customer_ref
 * @property string $gateway_token
 * @property PaymentGatewayMode|null $gateway_mode
 * @property string $brand
 * @property string $last4
 * @property int $exp_month
 * @property int $exp_year
 * @property PaymentMethodStatus $status
 * @property Carbon|null $last_failed_at
 * @property Carbon|null $removed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    /** What the parent is told when the card was saved in the other payment mode (R266). */
    public const READD_NOTICE = 'We changed how payments are processed, so please add your card again. Until you do, weekly lessons cannot be charged.';

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
            'gateway_mode' => PaymentGatewayMode::class,
            'last_failed_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * The account's live card: one at most (`payment_methods_one_live_per_account`).
     *
     * @param  Builder<PaymentMethod>  $query
     * @return Builder<PaymentMethod>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    /**
     * The Stripe mode this card's customer and token exist in (R266). A Stripe card saved before the column
     * existed was made against the test account, so no value means `test`; another gateway has no mode.
     */
    public function gatewayMode(): ?PaymentGatewayMode
    {
        return $this->gateway === 'stripe' ? ($this->gateway_mode ?? PaymentGatewayMode::Test) : null;
    }

    /**
     * True when this is a Stripe card saved in a different mode than `$against`, or than the active Stripe row when
     * none is given: its customer and token do not exist there, so it cannot be charged and the parent has to add
     * the card again (R266). Read at the moment of asking, not stored, so there is nothing to undo if the row is
     * switched back. A caller that already holds the driver passes its mode, which also saves the lookup.
     */
    public function savedInOtherMode(?PaymentGatewayMode $against = null): bool
    {
        if ($this->gateway !== 'stripe') {
            return false;
        }

        if ($against === null) {
            $active = app(PaymentGatewayManager::class)->activeRow();

            if ($active === null || $active->code !== 'stripe') {
                return false;
            }

            $against = $active->mode;
        }

        return $against !== $this->gatewayMode();
    }

    /**
     * Chargeable now: not removed, `active` and not past the last day of its expiry month. `allowRemoved` is for
     * resuming a charge that began before the card was removed.
     */
    public function isUsable(bool $allowRemoved = false): bool
    {
        $lastDay = CarbonImmutable::create($this->exp_year, $this->exp_month, 1)->endOfMonth()->startOfDay();

        return ($allowRemoved || $this->removed_at === null) && $this->status === PaymentMethodStatus::Active && ! $lastDay->lessThan(now()->startOfDay()) && ! $this->savedInOtherMode();
    }

    /**
     * What the card page tells the parent about this card, or null when there is nothing to say: it has
     * expired or been marked failed (replace it), or it expires within the next 30 days.
     */
    public function expiryNotice(): ?string
    {
        $lastDay = CarbonImmutable::create($this->exp_year, $this->exp_month, 1)->endOfMonth()->startOfDay();
        $today = now()->startOfDay();

        if ($this->savedInOtherMode()) {
            return __(self::READD_NOTICE);
        }

        if ($lastDay->lessThan($today)) {
            return __('This card has expired. Add a new one so weekly lessons keep being charged.');
        }

        if ($this->status !== PaymentMethodStatus::Active) {
            return __('The last charge to this card failed. Add a new one so weekly lessons keep being charged.');
        }

        if ($lastDay->lessThanOrEqualTo($today->addDays(30))) {
            return __('This card expires soon. Add a new one before it does so weekly lessons keep being charged.');
        }

        return null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_user_id')->withTrashed();
    }
}
