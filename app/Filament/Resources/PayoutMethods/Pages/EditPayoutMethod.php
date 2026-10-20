<?php

namespace App\Filament\Resources\PayoutMethods\Pages;

use App\Actions\RecordAuditLog;
use App\Filament\Resources\PayoutMethods\PayoutMethodResource;
use App\Models\PayoutMethod;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin edits a payout method's name, whether it is offered, and the countries it is offered in. Countries are
 * saved upper-case, unique, as a list (an empty list means every country). Each change is audited with the fields
 * that changed.
 */
class EditPayoutMethod extends EditRecord
{
    protected static string $resource = PayoutMethodResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof PayoutMethod) {
            return $record;
        }

        $before = self::editable($record);

        $record->fill($data);
        $record->updated_by = auth()->user()?->id;
        $record->save();

        $after = self::editable($record);
        $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $before[$field] !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            app(RecordAuditLog::class)(
                auth()->user(),
                'payout_method.updated',
                $record,
                array_intersect_key($before, array_flip($changed)),
                array_intersect_key($after, array_flip($changed)),
            );
        }

        return $record;
    }

    /**
     * @return array{name: string, is_active: bool, countries: list<string>}
     */
    private static function editable(PayoutMethod $method): array
    {
        return [
            'name' => $method->name,
            'is_active' => $method->is_active,
            'countries' => $method->countries,
        ];
    }
}
