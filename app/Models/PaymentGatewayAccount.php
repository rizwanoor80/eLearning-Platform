<?php

namespace App\Models;

use App\Enums\PaymentGatewayCode;
use App\Enums\PaymentGatewayMode;
use App\Enums\RecurringSlotStatus;
use App\Providers\PaymentGatewayServiceProvider;
use Database\Factories\PaymentGatewayAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One payment-gateway registry entry (PRD §12, invariant 16; table `payment_gateways`). The class
 * is not called `PaymentGateway` because that is the driver interface in `App\Services\Payments`.
 * Credentials are an encrypted array, hidden from every serialisation so they can never reach an
 * Inertia prop, a JSON response or a log line built from `toArray()`.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property PaymentGatewayMode $mode
 * @property array<string, string>|null $credentials
 * @property bool $supports_saved_cards
 * @property bool $is_active
 * @property int $sort
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PaymentGatewayAccount extends Model
{
    /** @use HasFactory<PaymentGatewayAccountFactory> */
    use HasFactory;

    protected $table = 'payment_gateways';

    protected $fillable = ['code', 'name', 'mode', 'credentials', 'supports_saved_cards', 'is_active', 'sort', 'updated_by'];

    /**
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    protected static function booted(): void
    {
        // Below the admin UI, so a seeder, tinker or a test hits the same rules as a form does.
        static::saving(function (PaymentGatewayAccount $gateway): void {
            // Live money is a named authorisation (HOW-WE-WORK §8): while the owner-only flag is
            // off, no row — active or not — may be saved into live mode. Deactivating a row that is
            // already live (the flag was turned off under it) stays possible: mode is not dirty.
            if ($gateway->mode === PaymentGatewayMode::Live && ($gateway->isDirty('mode') || $gateway->is_active)) {
                if (($problem = $gateway->liveModeProblem()) !== null) {
                    throw new LogicException($problem);
                }
            }

            // R266: a Stripe row whose mode disagrees with its keys would stamp every reference with the wrong mode.
            if (($gateway->is_active || $gateway->isDirty(['mode', 'credentials'])) && ($problem = $gateway->keyModeProblem()) !== null) {
                throw new LogicException($problem);
            }

            if ($gateway->is_active && ($problem = $gateway->activationProblem()) !== null) {
                throw new LogicException($problem);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'mode' => PaymentGatewayMode::class,
            'credentials' => 'encrypted:array',
            'supports_saved_cards' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<PaymentGatewayAccount>  $query
     * @return Builder<PaymentGatewayAccount>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function gatewayCode(): ?PaymentGatewayCode
    {
        return PaymentGatewayCode::tryFrom($this->code);
    }

    /**
     * Whether a credential key holds a non-empty value. The admin screen shows only this, never
     * the value.
     */
    public function hasCredential(string $key): bool
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '';
    }

    public function hasCompleteCredentials(): bool
    {
        $code = $this->gatewayCode();

        if ($code === null) {
            return false;
        }

        foreach ($code->requiredCredentialKeys() as $key) {
            if (! $this->hasCredential($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why this row may not be in `live` mode, or null when it may: the fake gateway never moves
     * real money, and nothing is live while the owner-only flag (`payments.live_mode_allowed`) is
     * off.
     */
    public function liveModeProblem(): ?string
    {
        if ($this->gatewayCode() === PaymentGatewayCode::Fake) {
            return 'The fake gateway cannot run in live mode.';
        }

        if (! config('payments.live_mode_allowed')) {
            return "Live mode is not allowed on this server, so {$this->name} cannot be switched to live.";
        }

        return null;
    }

    /**
     * Why the keys on a Stripe row do not belong to its mode, or null when they do (or there is nothing to check).
     * Only the prefix is read (`sk_test_`, `rk_live_`, `pk_live_`), and it is never put in the message.
     */
    public function keyModeProblem(): ?string
    {
        if ($this->gatewayCode() !== PaymentGatewayCode::Stripe) {
            return null;
        }

        $word = $this->mode === PaymentGatewayMode::Live ? 'live' : 'test';

        foreach (['secret_key' => ['sk_', 'rk_'], 'publishable_key' => ['pk_']] as $key => $kinds) {
            $value = $this->credentials[$key] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            $matches = false;

            foreach ($kinds as $kind) {
                $matches = $matches || str_starts_with($value, "{$kind}{$word}_");
            }

            if (! $matches) {
                return "The Stripe {$key} on {$this->name} is not a {$word}-mode key, so the mode and the keys disagree. Fix one of them.";
            }
        }

        return null;
    }

    /**
     * Why this row may not be the active gateway, or null when it may. The fake gateway is an
     * allow-list, not a deny-list (R131, ADR-022): only `PaymentGatewayServiceProvider::
     * FAKE_ENVIRONMENTS` may activate it, so a typo or a new environment name fails closed. A code
     * with no driver, a driver with missing credentials and a live row while live is not allowed
     * are refused everywhere. A gateway without saved-card support cannot be active while a weekly
     * slot is active: every lesson of a slot is charged off-session to a saved card.
     */
    public function activationProblem(): ?string
    {
        $code = $this->gatewayCode();

        if ($code === null || ! $code->hasDriver()) {
            return "There is no driver for {$this->name} yet, so it cannot be made active.";
        }

        if ($code === PaymentGatewayCode::Fake && ! PaymentGatewayServiceProvider::fakeGatewayAllowed()) {
            return 'The fake gateway cannot be active outside local, testing and rehearsal.';
        }

        if ($this->mode === PaymentGatewayMode::Live && ($problem = $this->liveModeProblem()) !== null) {
            return $problem;
        }

        if (! $this->hasCompleteCredentials()) {
            return "{$this->name} is missing credentials, so it cannot be made active.";
        }

        if (! $this->supports_saved_cards && RecurringSlot::query()->where('status', RecurringSlotStatus::Active)->exists()) {
            return "{$this->name} cannot save cards, and weekly slots are active that charge a saved card every week.";
        }

        return null;
    }
}
