<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerService;
use App\Services\Ops\OpsStatusReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * R193: the ledger line of `/ops/status` is *read* from the cache this command writes, never computed
 * in a request. Runs the same reads `ledger:verify` runs (`LedgerService::unbalancedLessons()`, `strandedPayments()` and, since 23h,
 * `unbalancedPayouts()` and `strayEntries()`, which turn the verdict to `fail` without a count of its own) and stores the verdict, the two counts and the time. It records a failing
 * ledger as `fail` and still exits 0 — the check ran; `ledger:verify` stays the gate that exits 1.
 * Read-only on the database; a second run just overwrites the entry.
 */
class RecordLedgerCheck extends Command
{
    protected $signature = 'ops:record-ledger-check';

    protected $description = 'Record the ledger zero-sum check for /ops/status (read-only, writes one cache entry)';

    public function handle(LedgerService $ledger): int
    {
        if (! Schema::hasTable('ledger_entries')) {
            $this->store('fail', null, null);
            $this->error('ledger_entries does not exist — recorded as fail.');

            return self::SUCCESS;
        }

        $unbalanced = $ledger->unbalancedLessons()->count();
        $stranded = $ledger->strandedPayments()->count();
        $result = $unbalanced === 0 && $stranded === 0 && $ledger->unbalancedPayouts()->isEmpty() && $ledger->strayEntries()->isEmpty() ? 'ok' : 'fail';

        $this->store($result, $unbalanced, $stranded);
        $this->info("Ledger check recorded: {$result} ({$unbalanced} unbalanced lessons, {$stranded} stranded payments).");

        return self::SUCCESS;
    }

    private function store(string $result, ?int $unbalanced, ?int $stranded): void
    {
        Cache::forever(OpsStatusReport::LEDGER_CHECK_KEY, [
            'result' => $result,
            'checked_at' => now()->getTimestamp(),
            'unbalanced_lessons' => $unbalanced,
            'stranded_payments' => $stranded,
        ]);
    }
}
