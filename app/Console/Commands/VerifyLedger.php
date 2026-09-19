<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class VerifyLedger extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Assert that every lesson\'s ledger entries add up to exactly zero (invariant #1)';

    /**
     * Exit 1 when any lesson does not sum to zero (or the ledger table is missing).
     * On an empty ledger it passes trivially — the proof of the invariant is the
     * zero-sum assertion in every money test; this command is the check to run
     * against a real database (trustutor-rehearsal after a deploy, production
     * nightly from CP5).
     */
    public function handle(LedgerService $ledger): int
    {
        if (! Schema::hasTable('ledger_entries')) {
            $this->error('ledger_entries does not exist — migrate first.');

            return self::FAILURE;
        }

        $unbalanced = $ledger->unbalancedLessons();

        if ($unbalanced->isEmpty()) {
            $this->info('Ledger OK: every lesson sums to zero.');

            return self::SUCCESS;
        }

        foreach ($unbalanced as $row) {
            $this->error("Lesson {$row['lesson_id']} sums to {$row['total']} fils, not zero.");
        }

        return self::FAILURE;
    }
}
