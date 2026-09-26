<?php

namespace App\Actions\Video;

use App\Actions\RecordAuditLog;
use App\Models\User;
use App\Models\VideoProvider;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Makes one registry row the active video provider and deactivates the rest, in one transaction
 * so the one-active index never trips. The row's own guard (`VideoProvider::activationProblem()`)
 * refuses a fake provider in production, a code with no driver and incomplete credentials, whether
 * this action or anything else saves the row.
 */
class ActivateVideoProvider
{
    /**
     * @throws LogicException when the row may not be active
     */
    public function __invoke(VideoProvider $provider, ?User $actor): void
    {
        if ($provider->is_active) {
            return;
        }

        if (($problem = $provider->activationProblem()) !== null) {
            throw new LogicException($problem);
        }

        DB::transaction(function () use ($provider, $actor): void {
            $previous = VideoProvider::query()->active()->value('code');

            VideoProvider::query()->where('is_active', true)->update(['is_active' => false]);

            $provider->forceFill(['is_active' => true, 'updated_by' => $actor?->id])->save();

            app(RecordAuditLog::class)($actor, 'video_provider.activated', $provider, ['active' => $previous], ['active' => $provider->code]);
        });
    }
}
