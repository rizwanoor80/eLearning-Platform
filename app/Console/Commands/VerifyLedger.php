<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerService;
use App\Support\Payments\UnheldCaptures;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class VerifyLedger extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Assert that every lesson\'s and every payout\'s ledger entries add up to exactly zero (invariant #1)';

    /**
     * Exit 1 when any lesson does not sum to zero (or the ledger table is missing).
     * On an empty ledger it passes trivially — the proof of the invariant is the
     * zero-sum assertion in every money test; this command is the check to run
     * against a real database (trustutor-rehearsal after a deploy, production
     * nightly from CP5).
     */
    public function handle(LedgerService $ledger, UnheldCaptures $unheldCaptures): int
    {
        if (! Schema::hasTable('ledger_entries')) {
            $this->error('ledger_entries does not exist — migrate first.');

            return self::FAILURE;
        }

        $unbalanced = $ledger->unbalancedLessons();

        foreach ($unbalanced as $row) {
            $this->error("Lesson {$row['lesson_id']} sums to {$row['total']} fils, not zero.");
        }

        $stranded = $ledger->strandedPayments();

        foreach ($stranded as $row) {
            $this->error("Payment {$row['payment_id']} on lesson {$row['lesson_id']} is {$row['status']} with no matching ledger hold (invariant #1).");
        }

        $unheld = $unheldCaptures();

        // A captured payment with ledger entries but no Hold is in both lists: one line for it (R277).
        $unheld = $unheld->reject(fn (array $row): bool => $stranded->contains('payment_id', $row['payment_id']))->values();

        foreach ($unheld as $row) {
            $this->error("Payment {$row['payment_id']} on lesson {$row['lesson_id']} is {$row['status']} for {$row['amount']} fils but the ledger holds {$row['held']} fils for it (invariant #1).");
        }

        $payouts = $ledger->unbalancedPayouts();

        foreach ($payouts as $row) {
            $this->error("Payout line {$row['payout_id']} ({$row['status']}, {$row['amount']} fils) does not match the ledger: entries sum to {$row['total']}, tutor account {$row['tutor_total']}.");
        }

        $stray = $ledger->strayEntries();

        foreach ($stray as $id) {
            $this->error("Ledger entry {$id} belongs to neither a lesson nor a payout (invariant #1).");
        }

        if ($unbalanced->isEmpty() && $stranded->isEmpty() && $unheld->isEmpty() && $payouts->isEmpty() && $stray->isEmpty()) {
            $this->info('Ledger OK: every lesson and every payout sums to zero.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
