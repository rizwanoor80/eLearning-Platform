<?php

namespace App\Filament\Resources\YearGroups\Pages;

use App\Actions\RecordAuditLog;
use App\Actions\YearGroups\RederiveSubjectTiers;
use App\Filament\Concerns\AuditsResourceChanges;
use App\Filament\Resources\YearGroups\YearGroupResource;
use App\Models\YearGroup;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditYearGroup extends EditRecord
{
    use AuditsResourceChanges;

    protected static string $resource = YearGroupResource::class;

    /**
     * Delete exists only while nothing points at the year group; called anyway
     * (a stale page) it refuses with a message instead of hitting the foreign
     * key, which stays as the backstop.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (YearGroup $record): bool => ! $record->isReferenced())
                ->before(function (DeleteAction $action, YearGroup $record): void {
                    if ($record->isReferenced()) {
                        Notification::make()->title('Still in use')->body('A learner or a tutor subject uses this year group, so it cannot be deleted.')->danger()->send();
                        $action->cancel();

                        return;
                    }
                })
                ->after(fn (YearGroup $record) => $this->auditDeleted($record)),
        ];
    }

    /**
     * The curriculum is fixed once a year group exists (the form field is
     * disabled); dropping it here as well means a crafted request cannot move it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['curriculum_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof YearGroup) {
            return;
        }

        $touchedTier = $record->wasChanged(['level_tier', 'sort']);

        $this->auditUpdated('year_group.updated');

        if ($touchedTier) {
            $result = app(RederiveSubjectTiers::class)(auth()->user(), $record);

            if ($result['changed'] > 0) {
                Notification::make()->title('Tutor subject tiers updated')
                    ->body($result['changed'].' subject row(s) now have a different level tier. Tutors\' rates are re-checked at completion, approval and booking.')
                    ->warning()->send();
            }
        }
    }

    private function auditDeleted(YearGroup $record): void
    {
        app(RecordAuditLog::class)(auth()->user(), 'year_group.deleted', $record, $record->getAttributes(), null);
    }
}
