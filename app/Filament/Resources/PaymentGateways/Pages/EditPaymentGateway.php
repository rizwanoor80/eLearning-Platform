<?php

namespace App\Filament\Resources\PaymentGateways\Pages;

use App\Actions\RecordAuditLog;
use App\Filament\Resources\PaymentGateways\PaymentGatewayResource;
use App\Filament\Resources\PaymentGateways\Schemas\PaymentGatewayForm;
use App\Models\PaymentGatewayAccount;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Credentials are write-only. The form is never filled from them, a blank input keeps the stored
 * value, and the typed value is cleared from component state after saving. The audit row names the
 * keys that changed, never a value.
 */
class EditPaymentGateway extends EditRecord
{
    protected static string $resource = PaymentGatewayResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PaymentGatewayAccount $record */
        $credentials = $record->credentials ?? [];
        $changedKeys = [];

        foreach ($data as $field => $value) {
            if (! str_starts_with((string) $field, PaymentGatewayForm::SECRET_PREFIX)) {
                continue;
            }

            unset($data[$field]);
            $key = substr((string) $field, strlen(PaymentGatewayForm::SECRET_PREFIX));

            if (is_string($value) && trim($value) !== '' && ($credentials[$key] ?? null) !== trim($value)) {
                $credentials[$key] = trim($value);
                $changedKeys[] = $key;
            }
        }

        $before = $this->audited($record);

        $record->fill($data);

        if ($changedKeys !== []) {
            $record->credentials = $credentials;
        }

        $record->updated_by = auth()->user()?->id;

        // The model's saving guard is the rule below this screen: an active row cannot be left
        // incomplete or without saved cards while weekly slots run. Its message names no value.
        try {
            $record->save();
        } catch (LogicException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
            $this->clearSecretState();
            $this->halt();
        }

        $after = $this->audited($record);
        $changedFields = array_keys(array_diff_assoc($after, $before));

        if ($changedFields !== [] || $changedKeys !== []) {
            app(RecordAuditLog::class)(
                auth()->user(),
                'payment_gateway.updated',
                $record,
                array_intersect_key($before, array_flip($changedFields)),
                array_intersect_key($after, array_flip($changedFields)) + ($changedKeys !== [] ? ['credentials_changed' => $changedKeys] : []),
            );
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function audited(PaymentGatewayAccount $record): array
    {
        return ['name' => $record->name, 'mode' => $record->mode->value, 'supports_saved_cards' => $record->supports_saved_cards];
    }

    protected function afterSave(): void
    {
        $this->clearSecretState();
    }

    /**
     * A typed secret never stays in component state, whether the save went through or was refused.
     */
    private function clearSecretState(): void
    {
        foreach (array_keys($this->data ?? []) as $field) {
            if (str_starts_with((string) $field, PaymentGatewayForm::SECRET_PREFIX)) {
                $this->data[$field] = null;
            }
        }
    }
}
