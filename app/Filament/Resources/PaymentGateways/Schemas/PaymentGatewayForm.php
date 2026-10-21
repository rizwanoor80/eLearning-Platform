<?php

namespace App\Filament\Resources\PaymentGateways\Schemas;

use App\Enums\PaymentGatewayCode;
use App\Enums\PaymentGatewayMode;
use App\Models\PaymentGatewayAccount;
use App\Support\Payments\StripeModeReadiness;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Credential inputs are not model attributes (`secret_<key>`), so the form is never filled from the
 * stored value; they are password inputs whose state is cleared after every save. A blank input
 * keeps what is stored (see EditPaymentGateway). Choosing `live` is a named authorisation (HOW-WE-WORK
 * §8): the model refuses it, for every row, unless the owner-only server flag allows it (R246).
 */
class PaymentGatewayForm
{
    public const SECRET_PREFIX = 'secret_';

    /** Not a model attribute: the owner's confirmation that a Stripe mode change leaves cards to add again (R266). */
    public const MODE_SWITCH_CONFIRMATION = 'mode_switch_confirmed';

    public static function configure(Schema $schema): Schema
    {
        $components = [
            TextInput::make('name')->required()->maxLength(100),
            Select::make('mode')
                ->options(collect(PaymentGatewayMode::cases())->mapWithKeys(fn (PaymentGatewayMode $mode): array => [$mode->value => ucfirst($mode->value)])->all())
                ->required()
                ->selectablePlaceholder(false)
                ->live()
                ->helperText('Live moves real money and is refused unless the server owner has allowed it.')
                ->disabled(fn (?PaymentGatewayAccount $record): bool => $record?->code === PaymentGatewayCode::Fake->value),
            Toggle::make(self::MODE_SWITCH_CONFIRMATION)
                ->label('I understand what changing the Stripe mode does')
                ->visible(fn (Get $get, ?PaymentGatewayAccount $record): bool => self::changesStripeMode($record, $get('mode')))
                ->accepted()
                ->validationMessages(['accepted' => 'Confirm that you understand before changing the Stripe mode.'])
                ->helperText(fn (Get $get, ?PaymentGatewayAccount $record): string => self::switchWarning($record, $get('mode'))),
            Toggle::make('supports_saved_cards')
                ->label('Saves cards for weekly slots')
                ->helperText('Weekly slots charge a saved card every week, so a gateway without this cannot be active while any slot is active.')
                ->disabled(fn (?PaymentGatewayAccount $record): bool => $record?->code !== PaymentGatewayCode::Fake->value),
        ];

        foreach (self::allCredentialKeys() as $key) {
            $components[] = TextInput::make(self::SECRET_PREFIX.$key)
                ->label(ucfirst(str_replace('_', ' ', $key)))
                ->password()
                ->revealable(false)
                ->autocomplete('new-password')
                ->maxLength(500)
                ->visible(fn (?PaymentGatewayAccount $record): bool => in_array($key, $record?->gatewayCode()?->credentialKeys() ?? [], true))
                ->placeholder(fn (?PaymentGatewayAccount $record): string => $record?->hasCredential($key) ? 'Set — leave blank to keep' : 'Not set')
                ->helperText('Stored encrypted. It is never shown again after saving.');
        }

        return $schema->components($components);
    }

    public static function changesStripeMode(?PaymentGatewayAccount $record, mixed $chosen): bool
    {
        return $record !== null
            && $record->code === PaymentGatewayCode::Stripe->value
            && PaymentGatewayMode::tryFrom((string) $chosen) !== null
            && $record->mode->value !== $chosen;
    }

    /**
     * What the change leaves behind, counted from the database (the same numbers as `payments:mode-readiness`).
     */
    private static function switchWarning(?PaymentGatewayAccount $record, mixed $chosen): string
    {
        $target = PaymentGatewayMode::tryFrom((string) $chosen);

        if ($record === null || $target === null) {
            return '';
        }

        $r = app(StripeModeReadiness::class)->for($target);

        return sprintf(
            'Cards, customers and payments exist in one Stripe mode only. After this change %d saved cards (%d active weekly slots) cannot be charged until the parent adds the card again; '
            .'nothing is deleted and switching back makes them usable again. Payments made in the mode being left can no longer be refunded here (%d refunds are still open). '
            .'%d payments are still pending. A failed weekly charge for this reason counts toward the auto-pause strike like any other failed charge. '
            .'Replace the API keys and the webhook secret in the same save. The confirmation is written to the audit log.',
            $r['unusable_cards'], $r['slots_needing_new_card'], $r['open_refunds'], $r['pending_payments'],
        );
    }

    /**
     * @return list<string>
     */
    private static function allCredentialKeys(): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn (PaymentGatewayCode $code): array => $code->credentialKeys(),
            PaymentGatewayCode::cases(),
        ))));
    }
}
