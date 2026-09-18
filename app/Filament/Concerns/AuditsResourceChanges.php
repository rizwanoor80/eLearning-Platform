<?php

namespace App\Filament\Concerns;

use App\Actions\RecordAuditLog;
use Illuminate\Support\Arr;

/**
 * For Filament create/edit pages of admin-managed configuration (document
 * types, price bands): every change writes an audit row with the admin as
 * actor. Raw attributes are logged, so a Money-cast column appears as its
 * integer fils.
 */
trait AuditsResourceChanges
{
    protected function auditCreated(string $action): void
    {
        $record = $this->getRecord();

        app(RecordAuditLog::class)(
            auth()->user(),
            $action,
            $record,
            null,
            Arr::except($record->getAttributes(), ['id', 'created_at', 'updated_at']),
        );
    }

    protected function auditUpdated(string $action): void
    {
        $record = $this->getRecord();
        $changes = Arr::except($record->getChanges(), ['updated_at']);

        if ($changes === []) {
            return;
        }

        app(RecordAuditLog::class)(
            auth()->user(),
            $action,
            $record,
            array_intersect_key($record->getPrevious(), $changes),
            $changes,
        );
    }
}
