<?php

namespace App\Console\Commands;

use App\Models\VideoWebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * R180: delivery records are a diagnostic window, not history — rows older than the retention
 * (30 days) go. Deletes in chunks so a large table never holds one long lock; a second run finds
 * nothing and is a no-op. The attendance record (`video_webhook_events`) is never touched.
 */
class PruneVideoWebhookDeliveries extends Command
{
    protected $signature = 'video:prune-webhook-deliveries';

    protected $description = 'Delete video webhook delivery records older than the retention period';

    public function handle(): int
    {
        $cutoff = Date::now()->subDays((int) config('video.webhook_delivery_retention_days'));
        $deleted = 0;

        do {
            $count = VideoWebhookDelivery::query()
                ->whereIn('id', VideoWebhookDelivery::query()->where('received_at', '<', $cutoff)->orderBy('id')->limit(1000)->select('id'))
                ->delete();
            $deleted += $count;
        } while ($count > 0);

        $this->info("Pruned {$deleted} webhook deliveries older than {$cutoff->utc()->format('Y-m-d H:i:s')} UTC.");

        return self::SUCCESS;
    }
}
