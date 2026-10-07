<?php

namespace App\Console\Commands;

use App\Models\VideoWebhookDelivery;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * R180: what has reached the video webhook endpoint lately, from the delivery records. Read-only and
 * payload-free by construction — the table holds statuses, sizes and key *names*, so there is no body,
 * header, signature or value for this command to print.
 */
class ShowVideoWebhooks extends Command
{
    protected $signature = 'video:webhooks {--since=24h : How far back to look: a number of minutes (30m), hours (24h) or days (7d)} {--limit=50 : The most recent deliveries to list}';

    protected $description = 'List recent video webhook deliveries (status, event type, key names — never a payload)';

    public function handle(): int
    {
        $since = $this->parseSince((string) $this->option('since'));
        $limit = max(1, min(500, (int) $this->option('limit')));

        if ($since === null) {
            $this->error('--since must be a whole number followed by m, h or d, for example 30m, 24h or 7d.');

            return self::FAILURE;
        }

        $deliveries = VideoWebhookDelivery::query()->where('received_at', '>=', $since);

        $this->line(sprintf('%d deliveries since %s UTC.', (clone $deliveries)->count(), $since->utc()->format('Y-m-d H:i:s')));

        $summary = (clone $deliveries)
            ->selectRaw('provider_code, http_status, outcome, count(*) as total')
            ->groupBy('provider_code', 'http_status', 'outcome')
            ->orderBy('provider_code')->orderBy('http_status')->orderBy('outcome')
            ->get();

        if ($summary->isEmpty()) {
            return self::SUCCESS;
        }

        $this->table(
            ['provider', 'http', 'outcome', 'count'],
            $summary->map(fn (VideoWebhookDelivery $row): array => [$row->provider_code, $row->http_status ?? '-', $row->outcome ?? '-', $row->getAttribute('total')])->all(),
        );

        $this->table(
            ['received (UTC)', 'provider', 'http', 'outcome', 'event type', 'event id', 'bytes', 'top-level keys', 'payload keys'],
            (clone $deliveries)->latest('received_at')->latest('id')->limit($limit)->get()->map(fn (VideoWebhookDelivery $row): array => [
                $row->received_at->utc()->format('Y-m-d H:i:s'),
                $row->provider_code,
                $row->http_status ?? '-',
                $row->outcome ?? '-',
                $row->event_type ?? '-',
                $row->event_id ?? '-',
                $row->body_length,
                $row->top_level_keys === null ? '-' : implode(',', $row->top_level_keys),
                $row->payload_keys === null ? '-' : implode(',', $row->payload_keys),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function parseSince(string $value): ?CarbonInterface
    {
        if (preg_match('/^(\d{1,4})([mhd])$/', trim($value), $matches) !== 1 || (int) $matches[1] === 0) {
            return null;
        }

        $amount = (int) $matches[1];
        $now = Date::now();

        return match ($matches[2]) {
            'm' => $now->subMinutes($amount),
            'h' => $now->subHours($amount),
            default => $now->subDays($amount),
        };
    }
}
