<?php

namespace App\Filament\Resources\PayoutBatches\Pages;

use App\Actions\Payouts\GeneratePayoutBatch;
use App\Exceptions\PayoutException;
use App\Filament\Resources\PayoutBatches\PayoutBatchResource;
use App\Support\Money;
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
                    try {
                        $result = app(GeneratePayoutBatch::class)(auth()->user(), CarbonImmutable::parse((string) $data['period_end']));
                    } catch (PayoutException $e) {
                        Notification::make()->danger()->title('Batch not generated')->body($e->getMessage())->send();

                        return;
                    }

                    // R263: closed accounts that are still owed money are kept out of the batch and said so, batch or not.
                    if ($result->anonymisedOwed !== []) {
                        Notification::make()->warning()->title('Money owed to an anonymised account')
                            ->body(collect($result->anonymisedOwed)
                                ->map(fn (array $o): string => "Tutor #{$o['tutor_profile_id']}: ".Money::fils($o['amount_fils'])->format().' owed to an anonymised account — decide manually.')
                                ->implode("\n"))
                            ->persistent()->send();
                    }

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
