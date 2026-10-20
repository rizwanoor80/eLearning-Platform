<?php

namespace App\Http\Controllers\Tutor;

use App\Enums\PayoutLineStatus;
use App\Http\Controllers\Controller;
use App\Models\PayoutBatchLine;
use App\Models\TutorProfile;
use App\Services\Tutors\TutorEarnings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * R250: the tutor's own earnings in the five buckets, and (23h) their paid payouts with the payment reference the admin
 * recorded. Read-only; the definitions live on `TutorEarnings`. The platform's own line reference and the account
 * details are never sent.
 */
class TutorEarningsController extends Controller
{
    public function show(Request $request, TutorEarnings $earnings): Response
    {
        // A query, not `$user->tutorProfile`, so the profile is never loaded onto the request user (as the dashboard).
        $profile = TutorProfile::query()->where('user_id', $request->user()->id)->first();

        $buckets = $profile === null ? null : $earnings->buckets($profile);

        return Inertia::render('tutor/Earnings', [
            'buckets' => $buckets === null ? null : [
                'pending' => $buckets->pending->format(),
                'on_hold' => $buckets->onHold->format(),
                'available' => $buckets->available->format(),
                'processing' => $buckets->processing->format(),
                'paid_to_date' => $buckets->paidToDate->format(),
                'total' => $buckets->total()->format(),
                // R258: released money inside its dispute window is in pending; this is when it moves to available.
                'pending_available_on' => $buckets->pendingAvailableAt?->setTimezone($request->user()->timezone)->format('j M Y'),
            ],
            'payout_problem' => $profile?->payoutMethodProblem(),
            'payouts' => $profile === null ? [] : $this->paidLines($profile, $request->user()->timezone),
        ]);
    }

    /**
     * @return list<array{id: int, paid_at: string, amount: string, reference: string}>
     */
    private function paidLines(TutorProfile $profile, string $timezone): array
    {
        return array_values(PayoutBatchLine::query()
            ->where('tutor_profile_id', $profile->getKey())
            ->where('status', PayoutLineStatus::Paid->value)
            ->latest('paid_at')
            ->latest('id')
            ->get(['id', 'amount', 'paid_at', 'external_reference'])
            ->map(fn (PayoutBatchLine $line): array => [
                'id' => $line->id,
                'paid_at' => $line->paid_at->setTimezone($timezone)->format('j M Y'),
                'amount' => $line->amount->format(),
                'reference' => (string) $line->external_reference,
            ])
            ->all());
    }
}
