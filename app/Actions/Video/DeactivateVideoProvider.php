<?php

namespace App\Actions\Video;

use App\Actions\RecordAuditLog;
use App\Models\User;
use App\Models\VideoProvider;
use Illuminate\Support\Facades\DB;

/**
 * Turns the active provider off. New rooms then show an admin notice instead of being created;
 * lessons that already have a room keep working through their own provider (`forCode`).
 */
class DeactivateVideoProvider
{
    public function __invoke(VideoProvider $provider, ?User $actor): void
    {
        if (! $provider->is_active) {
            return;
        }

        DB::transaction(function () use ($provider, $actor): void {
            $provider->forceFill(['is_active' => false, 'updated_by' => $actor?->id])->save();

            app(RecordAuditLog::class)($actor, 'video_provider.deactivated', $provider, ['active' => $provider->code], ['active' => null]);
        });
    }
}
