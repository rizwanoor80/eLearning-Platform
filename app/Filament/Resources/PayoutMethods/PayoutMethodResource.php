<?php

namespace App\Filament\Resources\PayoutMethods;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\PayoutMethods\Pages\EditPayoutMethod;
use App\Filament\Resources\PayoutMethods\Pages\ListPayoutMethods;
use App\Filament\Resources\PayoutMethods\Schemas\PayoutMethodForm;
use App\Filament\Resources\PayoutMethods\Tables\PayoutMethodsTable;
use App\Models\PayoutMethod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The payout-method registry (R249). The set of rows is fixed by migration: an admin turns a method on or off and
 * says which countries it is offered in. No create, no delete.
 */
class PayoutMethodResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = PayoutMethod::class;

    protected static ?string $navigationLabel = 'Payout methods';

    protected static ?string $modelLabel = 'payout method';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return PayoutMethodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayoutMethodsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayoutMethods::route('/'),
            'edit' => EditPayoutMethod::route('/{record}/edit'),
        ];
    }
}
