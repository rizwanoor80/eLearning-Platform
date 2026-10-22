<?php

namespace App\Console\Commands;

use App\Services\Ops\Preflight;
use Illuminate\Console\Command;

/**
 * R275: the go-live gate. One PASS/FAIL line per check, never a value, and a non-zero exit when any check fails.
 * Read-only. On local and rehearsal it fails by design (`APP_ENV` is not production there); it is run on production
 * before the first real traffic.
 */
class RunOpsPreflight extends Command
{
    protected $signature = 'ops:preflight';

    protected $description = 'Check that this environment is ready for production traffic: one PASS or FAIL line per check, exit 1 on any failure';

    public function handle(Preflight $preflight): int
    {
        $failed = 0;

        foreach ($preflight->run() as $result) {
            $line = "{$result->status->label()}  {$result->name}".($result->note !== '' ? " — {$result->note}" : '');

            if ($result->passed()) {
                $this->line($line);

                continue;
            }

            $failed++;
            $this->error($line);
        }

        $failed === 0
            ? $this->info('Preflight passed.')
            : $this->error("Preflight failed: {$failed} check(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
