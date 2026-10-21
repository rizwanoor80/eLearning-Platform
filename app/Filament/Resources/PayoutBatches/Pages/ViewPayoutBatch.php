<?php

namespace App\Filament\Resources\PayoutBatches\Pages;

use App\Actions\Payouts\ExportPayoutBatchCsv;
use App\Enums\PayoutLineStatus;
use App\Enums\PayoutMethodCode;
use App\Exceptions\PayoutException;
use App\Filament\Resources\PayoutBatches\PayoutBatchResource;
use App\Models\PayoutBatch;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPayoutBatch extends ViewRecord
{
    protected static string $resource = PayoutBatchResource::class;

    /**
     * One download per method that still has a line to pay in this batch.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var PayoutBatch $batch */
        $batch = $this->getRecord();

        $methods = $batch->lines()
            ->where('status', PayoutLineStatus::Pending->value)
            ->distinct()
            ->pluck('payout_method');

        return collect(PayoutMethodCode::cases())
            ->filter(fn (PayoutMethodCode $m): bool => $methods->contains($m->value))
            ->sortBy(fn (PayoutMethodCode $m): int => $m->sort())
            ->map(fn (PayoutMethodCode $m): Action => Action::make('csv_'.$m->value)
                ->label('Download '.$m->label().' CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function () use ($batch, $m) {
                    try {
                        return app(ExportPayoutBatchCsv::class)(auth()->user(), $batch, $m);
                    } catch (PayoutException $e) {
                        Notification::make()->danger()->title('File not made')->body($e->getMessage())->persistent()->send();

                        return null;
                    }
                }))
            ->values()
            ->all();
    }
}
