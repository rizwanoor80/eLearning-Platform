<?php

namespace App\Filament\Resources\PaymentGateways\Schemas;

use App\Enums\PaymentGatewayCode;
use App\Enums\PaymentGatewayMode;
use App\Models\PaymentGatewayAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

    public static function configure(Schema $schema): Schema
    {
        $components = [
            TextInput::make('name')->required()->maxLength(100),
            Select::make('mode')
                ->options(collect(PaymentGatewayMode::cases())->mapWithKeys(fn (PaymentGatewayMode $mode): array => [$mode->value => ucfirst($mode->value)])->all())
                ->required()
                ->selectablePlaceholder(false)
                ->helperText('Live moves real money and is refused unless the server owner has allowed it.')
                ->disabled(fn (?PaymentGatewayAccount $record): bool => $record?->code === PaymentGatewayCode::Fake->value),
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
