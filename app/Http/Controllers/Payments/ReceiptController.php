<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Policies\PaymentPolicy;
use App\Services\Payments\ReceiptPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * R257: the parent's receipts. A list of the lessons the account has paid for, and one printable
 * receipt per payment. Read-only; a payment that is not the caller's, or never took money, is a 404.
 */
class ReceiptController extends Controller
{
    public function index(Request $request, ReceiptPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        $payments = Payment::query()
            ->where('payer_user_id', $user->id)
            ->whereNotNull('gateway_ref')
            ->whereIn('status', array_map(fn ($status) => $status->value, PaymentPolicy::paidStatuses()))
            ->with(['lesson.tutorProfile.user', 'lesson.learner'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(20)
            ->withQueryString();

        return Inertia::render('receipts/Index', [
            'receipts' => $payments->getCollection()->map(fn (Payment $payment): array => $presenter->row($payment, $user))->values()->all(),
            'previous_page_url' => $payments->previousPageUrl(),
            'next_page_url' => $payments->nextPageUrl(),
        ]);
    }

    public function show(Request $request, Payment $payment, ReceiptPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        // A payment that is someone else's is indistinguishable from one that does not exist.
        abort_unless(Gate::forUser($user)->allows('viewReceipt', $payment), 404);

        $payment->load(['lesson.tutorProfile.user', 'lesson.learner', 'lesson.subject:id,name']);

        return Inertia::render('receipts/Show', [
            'receipt' => $presenter->receipt($payment, $user),
        ]);
    }
}
