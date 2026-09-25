<?php

namespace App\Filament\Resources\RecurringSlots\Pages;

use App\Actions\RecurringSlots\EndRecurringSlot;
use App\Actions\RecurringSlots\PauseRecurringSlot;
use App\Actions\RecurringSlots\ResumeRecurringSlot;
use App\Enums\RecurringSlotStatus;
use App\Exceptions\RecurringSlotException;
use App\Filament\Resources\RecurringSlots\RecurringSlotResource;
use App\Models\RecurringSlot;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewRecurringSlot extends ViewRecord
{
    protected static string $resource = RecurringSlotResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->slotAction('pause', 'Pause', 'warning', RecurringSlotStatus::Active, function (RecurringSlot $record, ?string $note): void {
                app(PauseRecurringSlot::class)(auth()->user(), $record, note: $note);
            }, 'Weekly slot paused'),

            $this->slotAction('resume', 'Resume', 'success', RecurringSlotStatus::Paused, function (RecurringSlot $record, ?string $note): void {
                app(ResumeRecurringSlot::class)(auth()->user(), $record, $note);
            }, 'Weekly slot resumed'),

            $this->slotAction('end', 'End slot', 'danger', null, function (RecurringSlot $record, ?string $note): void {
                app(EndRecurringSlot::class)(auth()->user(), $record, $note);
            }, 'Weekly slot ended'),
        ];
    }

    /**
     * One admin action: an optional note, a confirmation, the domain Action, and a notice on refusal.
     * `$visibleWhen` null means "any slot that has not ended".
     *
     * @param  \Closure(RecurringSlot, ?string): void  $run
     */
    private function slotAction(string $name, string $label, string $color, ?RecurringSlotStatus $visibleWhen, \Closure $run, string $done): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->requiresConfirmation()
            ->schema([Textarea::make('note')->label('Note (kept in the audit log)')->maxLength(500)])
            ->visible(fn (RecurringSlot $record): bool => $visibleWhen === null
                ? $record->status !== RecurringSlotStatus::Ended
                : $record->status === $visibleWhen)
            ->action(function (RecurringSlot $record, array $data) use ($run, $done): void {
                try {
                    $run($record, filled($data['note'] ?? null) ? $data['note'] : null);
                    $record->refresh();
                    Notification::make()->title($done)->success()->send();
                } catch (RecurringSlotException $e) {
                    Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();
                }
            });
    }
}
