<?php

namespace App\Http\Controllers\Tutor;

use App\Http\Controllers\Controller;
use App\Models\TutorProfile;
use App\Services\Tutors\TutorEarnings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * R250: the tutor's own earnings in the five buckets. Read-only; the definitions live on `TutorEarnings`.
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
            ],
            'payout_problem' => $profile?->payoutMethodProblem(),
            'payouts' => [],
        ]);
    }
}
