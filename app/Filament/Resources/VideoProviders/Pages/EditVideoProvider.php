<?php

namespace App\Filament\Resources\VideoProviders\Pages;

use App\Actions\RecordAuditLog;
use App\Filament\Resources\VideoProviders\Schemas\VideoProviderForm;
use App\Filament\Resources\VideoProviders\VideoProviderResource;
use App\Models\VideoProvider;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Credentials are write-only. The form is never filled from them, a blank input keeps the stored
 * value, and the typed value is cleared from component state after saving. The audit row names the
 * keys that changed, never a value.
 */
class EditVideoProvider extends EditRecord
{
    protected static string $resource = VideoProviderResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var VideoProvider $record */
        $credentials = $record->credentials ?? [];
        $changedKeys = [];

        foreach ($data as $field => $value) {
            if (! str_starts_with((string) $field, VideoProviderForm::SECRET_PREFIX)) {
                continue;
            }

            unset($data[$field]);
            $key = substr((string) $field, strlen(VideoProviderForm::SECRET_PREFIX));

            if (is_string($value) && trim($value) !== '' && ($credentials[$key] ?? null) !== $value) {
                $credentials[$key] = trim($value);
                $changedKeys[] = $key;
            }
        }

        $before = $record->only(['name', 'supports_embed', 'supports_attendance_webhooks']);

        $record->fill($data);

        if ($changedKeys !== []) {
            $record->credentials = $credentials;
        }

        $record->updated_by = auth()->user()?->id;

        // Blank inputs keep what is stored, so no edit here can leave an active row incomplete; the
        // model's saving guard remains the backstop below this screen.
        $record->save();

        $after = $record->only(['name', 'supports_embed', 'supports_attendance_webhooks']);
        $changedFields = array_keys(array_diff_assoc($after, $before));

        if ($changedFields !== [] || $changedKeys !== []) {
            app(RecordAuditLog::class)(
                auth()->user(),
                'video_provider.updated',
                $record,
                array_intersect_key($before, array_flip($changedFields)),
                array_intersect_key($after, array_flip($changedFields)) + ($changedKeys !== [] ? ['credentials_changed' => $changedKeys] : []),
            );
        }

        return $record;
    }

    protected function afterSave(): void
    {
        foreach (array_keys($this->data ?? []) as $field) {
            if (str_starts_with((string) $field, VideoProviderForm::SECRET_PREFIX)) {
                $this->data[$field] = null;
            }
        }
    }
}
