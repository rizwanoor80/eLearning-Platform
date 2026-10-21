<?php

namespace App\Filament\Resources\PaymentRefunds\Tables;

use App\Actions\Payments\RequeueFailedRefund;
use App\Enums\PaymentRefundKind;
use App\Enums\PaymentRefundStatus;
use App\Exceptions\RefundRequeueException;
use App\Models\PaymentRefund;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentRefundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('payment'))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('payment.lesson_id')->label('Lesson'),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (PaymentRefundKind $state): string => $state->value),
                TextColumn::make('attempt'),
                TextColumn::make('amount')->state(fn (PaymentRefund $record): string => $record->amount->format()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (PaymentRefundStatus $state): string => $state->value)
                    ->color(fn (PaymentRefundStatus $state): string => match ($state) {
                        PaymentRefundStatus::Succeeded => 'success',
                        PaymentRefundStatus::Failed => 'danger',
                        PaymentRefundStatus::Requested => 'warning',
                    }),
                TextColumn::make('failure_reason')->label('Why it failed')->placeholder('—')->limit(60),
                TextColumn::make('requested_at')->label('Asked')->dateTime()->sortable(),
                TextColumn::make('completed_at')->label('Answered')->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(PaymentRefundStatus::cases())->mapWithKeys(fn (PaymentRefundStatus $s): array => [$s->value => $s->value])->all()),
                SelectFilter::make('kind')->options(collect(PaymentRefundKind::cases())->mapWithKeys(fn (PaymentRefundKind $k): array => [$k->value => $k->value])->all()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('requeue')
                    ->label('Re-queue')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (PaymentRefund $record): bool => $record->status === PaymentRefundStatus::Failed && $record->kind !== PaymentRefundKind::External)
                    ->requiresConfirmation()
                    ->modalDescription('Asks the gateway again as a new attempt, for no more than it can still return. The ledger is not changed.')
                    ->action(function (PaymentRefund $record): void {
                        try {
                            $new = app(RequeueFailedRefund::class)(auth()->user(), $record);
                        } catch (RefundRequeueException $e) {
                            Notification::make()->warning()->title($e->getMessage())->send();

                            return;
                        }

                        $new->status === PaymentRefundStatus::Requested
                            ? Notification::make()->success()->title("Attempt {$new->attempt} queued")->send()
                            : Notification::make()->warning()->title('Nothing left to refund at the gateway')->persistent()->send();
                    }),
            ]);
    }
}
