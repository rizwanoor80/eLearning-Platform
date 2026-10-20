<?php

namespace App\Filament\Resources\PaymentGateways;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\PaymentGateways\Pages\EditPaymentGateway;
use App\Filament\Resources\PaymentGateways\Pages\ListPaymentGateways;
use App\Filament\Resources\PaymentGateways\Schemas\PaymentGatewayForm;
use App\Filament\Resources\PaymentGateways\Tables\PaymentGatewaysTable;
use App\Models\PaymentGatewayAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The payment-gateway registry (PRD §12, invariant 16, R246). The set of rows is fixed by
 * migration — no create, no delete — and credentials are write-only: the edit form never receives
 * a stored value.
 */
class PaymentGatewayResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = PaymentGatewayAccount::class;

    protected static ?string $navigationLabel = 'Payment gateways';

    protected static ?string $modelLabel = 'payment gateway';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentGatewayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentGatewaysTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentGateways::route('/'),
            'edit' => EditPaymentGateway::route('/{record}/edit'),
        ];
    }
}
