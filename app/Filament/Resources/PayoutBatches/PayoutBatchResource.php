<?php

namespace App\Filament\Resources\PayoutBatches;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\PayoutBatches\Pages\ListPayoutBatches;
use App\Filament\Resources\PayoutBatches\Pages\ViewPayoutBatch;
use App\Filament\Resources\PayoutBatches\RelationManagers\LinesRelationManager;
use App\Filament\Resources\PayoutBatches\Tables\PayoutBatchesTable;
use App\Models\PayoutBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * 23h (R251): the payout batches. A batch is made by `GeneratePayoutBatch` and its lines are settled one by one by
 * `MarkPayoutLinePaid` / `MarkPayoutLineFailed`; there is no create form, no edit and no delete here. Account details are
 * never shown in the UI: they leave only in the per-method CSV, which is audited.
 */
class PayoutBatchResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = PayoutBatch::class;

    protected static ?string $navigationLabel = 'Payout batches';

    protected static ?string $modelLabel = 'payout batch';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

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
        return PayoutBatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayoutBatches::route('/'),
            'view' => ViewPayoutBatch::route('/{record}'),
        ];
    }
}
