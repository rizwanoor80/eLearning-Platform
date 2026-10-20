<?php

namespace App\Filament\Resources\PayoutBatches\Pages;

use App\Actions\Payouts\GeneratePayoutBatch;
use App\Filament\Resources\PayoutBatches\PayoutBatchResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPayoutBatches extends ListRecords
{
    protected static string $resource = PayoutBatchResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate batch')
                ->icon('heroicon-o-plus')
                ->requiresConfirmation()
                ->modalDescription('Puts every tutor with at least the payout minimum available, and a payout method that can be paid, into a new batch. Nothing is paid until you mark each line paid.')
                ->schema([
                    DatePicker::make('period_end')
                        ->label('Period end')
                        ->default(fn () => GeneratePayoutBatch::defaultPeriodEnd()->toDateString())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $result = app(GeneratePayoutBatch::class)(auth()->user(), CarbonImmutable::parse((string) $data['period_end']));

                    $skipped = collect($result->skipped)->map(fn (array $s): string => "{$s['tutor']}: {$s['reason']}")->implode("\n");

                    if ($result->batch === null) {
                        Notification::make()->warning()->title('No batch made')
                            ->body(trim('Nobody has the payout minimum available and can be paid.'."\n".$skipped))
                            ->persistent()->send();

                        return;
                    }

                    $notification = Notification::make()->success()
                        ->title("Batch {$result->batch->id} generated")
                        ->body($result->batch->lines()->count().' tutor(s) in the batch.'.($skipped === '' ? '' : "\nLeft out:\n".$skipped));

                    ($skipped === '' ? $notification : $notification->persistent())->send();
                }),
        ];
    }
}
