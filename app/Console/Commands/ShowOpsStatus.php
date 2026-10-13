<?php

namespace App\Console\Commands;

use App\Services\Ops\OpsStatusReport;
use Illuminate\Console\Command;

/**
 * R193: prints the document `GET /ops/status` returns — the same assembler, so the two cannot drift.
 * Local use (and a shell on a server the operator already holds); read-only, nothing to authenticate.
 */
class ShowOpsStatus extends Command
{
    protected $signature = 'ops:status';

    protected $description = 'Print the operator status document that GET /ops/status serves, as JSON';

    public function handle(OpsStatusReport $report): int
    {
        $this->line((string) json_encode($report->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
