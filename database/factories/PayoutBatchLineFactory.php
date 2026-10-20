<?php

namespace Database\Factories;

use App\Enums\PayoutLineStatus;
use App\Enums\PayoutMethodCode;
use App\Models\PayoutBatch;
use App\Models\PayoutBatchLine;
use App\Models\TutorProfile;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutBatchLine>
 */
class PayoutBatchLineFactory extends Factory
{
    /**
     * A pending bank-transfer line of AED 200.00 with synthetic account details. A test that needs the ledger entries of a
     * paid line goes through `MarkPayoutLinePaid`, not through this factory's `paid()` state (which is only a status).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payout_batch_id' => PayoutBatch::factory(),
            'tutor_profile_id' => TutorProfile::factory()->approved(),
            'payout_method' => PayoutMethodCode::BankTransfer->value,
            'amount' => Money::fils(20000),
            'currency' => 'AED',
            'reference' => 'TT-'.fake()->unique()->numerify('######-###'),
            'details' => ['bank_name' => 'Test Bank', 'account_name' => 'Test Tutor', 'iban' => 'GB00TEST00000000000000', 'swift' => 'TESTGB2L'],
            'status' => PayoutLineStatus::Pending,
        ];
    }

    public function email(PayoutMethodCode $code = PayoutMethodCode::Wise): static
    {
        return $this->state(fn () => ['payout_method' => $code->value, 'details' => ['email' => 'tutor@example.com']]);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => PayoutLineStatus::Paid, 'external_reference' => 'BANK-TEST-1', 'paid_at' => now()]);
    }
}
