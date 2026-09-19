<?php

namespace App\Filament\Resources\SitePages\Pages;

use App\Actions\Page\PublishPage;
use App\Filament\Resources\SitePages\SitePageResource;
use App\Models\Page;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * DATA_MODEL has no draft state, so there is nothing to "save" short of
 * publishing: the button is Publish, it writes a new version and the public
 * route shows it at once. The markdown editor's Preview tab is the preview.
 * An unchanged form publishes nothing.
 */
class EditSitePage extends EditRecord
{
    protected static string $resource = SitePageResource::class;

    private bool $published = false;

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Publish');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Page $record */
        $published = app(PublishPage::class)(auth()->user(), $record, (string) $data['title'], (string) $data['body']);

        $this->published = $published !== null;

        if (! $this->published) {
            Notification::make()->title('No changes to publish')->warning()->send();
        }

        return $record;
    }

    /**
     * Only announce "Published" when a version was actually written, so an
     * unchanged save shows the single "No changes" warning.
     */
    protected function getSavedNotification(): ?Notification
    {
        return $this->published ? Notification::make()->title('Published')->success() : null;
    }
}
