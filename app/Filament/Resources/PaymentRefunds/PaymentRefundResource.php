<?php

namespace App\Filament\Resources\PaymentRefunds;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\PaymentRefunds\Pages\ListPaymentRefunds;
use App\Filament\Resources\PaymentRefunds\Tables\PaymentRefundsTable;
use App\Models\PaymentRefund;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * 24b (R260): every gateway refund the platform asked for, and the ones made at the gateway by someone else. Read-only:
 * the one action is re-queueing an attempt the gateway refused (`RequeueFailedRefund`), which makes a new attempt and
 * never reopens the old one. The ledger is not touched from here.
 */
class PaymentRefundResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = PaymentRefund::class;

    protected static ?string $navigationLabel = 'Gateway refunds';

    protected static ?string $modelLabel = 'gateway refund';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return PaymentRefundsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentRefunds::route('/'),
        ];
    }
}
