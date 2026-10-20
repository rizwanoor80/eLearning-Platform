<?php

namespace App\Filament\Resources\PaymentGateways\Tables;

use App\Actions\Payments\ActivatePaymentGateway;
use App\Actions\Payments\DeactivatePaymentGateway;
use App\Models\PaymentGatewayAccount;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

class PaymentGatewaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('code')->badge(),
                TextColumn::make('mode')->badge(),
                IconColumn::make('supports_saved_cards')->label('Saved cards')->boolean(),
                IconColumn::make('credentials_complete')
                    ->label('Credentials')
                    ->boolean()
                    ->state(fn (PaymentGatewayAccount $record): bool => $record->hasCompleteCredentials()),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('sort')
            ->recordActions([
                Action::make('activate')
                    ->requiresConfirmation()
                    ->modalDescription('New bookings and weekly charges will go through this gateway. Payments already taken keep their own gateway reference.')
                    ->visible(fn (PaymentGatewayAccount $record): bool => ! $record->is_active)
                    ->action(function (PaymentGatewayAccount $record): void {
                        try {
                            app(ActivatePaymentGateway::class)($record, auth()->user());
                        } catch (LogicException $e) {
                            Notification::make()->danger()->title('Not activated')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title("{$record->name} is now the active payment gateway")->send();
                    }),
                Action::make('deactivate')
                    ->requiresConfirmation()
                    ->modalDescription('With no active gateway, parents cannot book lessons and weekly slots are not charged until one is activated.')
                    ->visible(fn (PaymentGatewayAccount $record): bool => $record->is_active)
                    ->action(fn (PaymentGatewayAccount $record) => app(DeactivatePaymentGateway::class)($record, auth()->user())),
                EditAction::make(),
            ]);
    }
}
