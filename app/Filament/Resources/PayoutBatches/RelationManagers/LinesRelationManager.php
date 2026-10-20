<?php

namespace App\Filament\Resources\PayoutBatches\RelationManagers;

use App\Actions\Payouts\MarkPayoutLineFailed;
use App\Actions\Payouts\MarkPayoutLinePaid;
use App\Enums\PayoutLineStatus;
use App\Exceptions\LedgerException;
use App\Exceptions\PayoutException;
use App\Models\PayoutBatchLine;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 23h (R251): the lines of one batch. A line is paid outside the platform (from the CSV) and recorded here with the bank's
 * or provider's own reference, or marked failed with a reason. Account details are not shown: they are in the audited CSV.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Payouts';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tutorProfile.user'))
            ->recordTitleAttribute('reference')
            ->columns([
                TextColumn::make('reference')->label('Reference')->searchable(),
                TextColumn::make('tutor')->label('Tutor')
                    ->state(fn (PayoutBatchLine $record): string => $record->tutorProfile->displayName()),
                TextColumn::make('payout_method')->label('Method')
                    ->formatStateUsing(fn (PayoutBatchLine $record): string => $record->method()->label()),
                TextColumn::make('amount')->label('Amount')
                    ->state(fn (PayoutBatchLine $record): string => $record->amount->format()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (PayoutLineStatus $state): string => $state->label())
                    ->color(fn (PayoutLineStatus $state): string => match ($state) {
                        PayoutLineStatus::Pending => 'warning',
                        PayoutLineStatus::Paid => 'success',
                        PayoutLineStatus::Failed => 'danger',
                    }),
                TextColumn::make('external_reference')->label('Payment reference')->placeholder('—'),
                TextColumn::make('failure_reason')->label('Why it failed')->placeholder('—')->wrap(),
                TextColumn::make('paid_at')->label('Paid')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('markPaid')
                    ->label('Mark paid')
                    ->color('success')
                    ->visible(fn (PayoutBatchLine $record): bool => $record->status === PayoutLineStatus::Pending)
                    ->modalDescription('Records that this payment has been made. It writes the payout to the ledger and cannot be undone.')
                    ->schema([
                        TextInput::make('external_reference')
                            ->label('Payment reference from the bank or provider')
                            ->required()
                            ->maxLength(191),
                    ])
                    ->action(function (PayoutBatchLine $record, array $data): void {
                        try {
                            app(MarkPayoutLinePaid::class)(auth()->user(), $record, (string) $data['external_reference']);
                        } catch (PayoutException|LedgerException $e) {
                            Notification::make()->danger()->title('Not marked paid')->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()->success()->title('Marked paid')->send();
                    }),
                Action::make('markFailed')
                    ->label('Mark failed')
                    ->color('danger')
                    ->visible(fn (PayoutBatchLine $record): bool => $record->status === PayoutLineStatus::Pending)
                    ->modalDescription('The payment did not go through. The money goes back to the tutor as available.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why did it fail?')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (PayoutBatchLine $record, array $data): void {
                        try {
                            app(MarkPayoutLineFailed::class)(auth()->user(), $record, (string) $data['reason']);
                        } catch (PayoutException $e) {
                            Notification::make()->danger()->title('Not marked failed')->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()->success()->title('Marked failed')->send();
                    }),
            ]);
    }
}
