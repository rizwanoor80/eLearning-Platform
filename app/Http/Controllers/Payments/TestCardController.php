<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\SaveTestCard;
use App\Enums\TestCard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StoreTestCardRequest;
use App\Models\Learner;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The fake driver's "Add a card" page (R100). It exists only while the fake gateway does: both
 * routes 404 wherever the fake gateway is not bound (production above all), it has no card-number
 * or CVC field, and CP5 replaces it with the real driver's capture.
 */
class TestCardController extends Controller
{
    public function create(Request $request): Response
    {
        abort_unless(SaveTestCard::available(), 404);

        /** @var User $user */
        $user = $request->user();
        $current = PaymentMethod::query()->where('account_user_id', $user->id)->first();

        return Inertia::render('payment-methods/Create', [
            'cards' => array_map(
                fn (TestCard $card): array => ['value' => $card->value, 'label' => $card->label().' (…'.$card->last4().')'],
                TestCard::cases(),
            ),
            'current' => $current === null ? null : [
                'brand' => $current->brand,
                'last4' => $current->last4,
                'expires' => sprintf('%02d/%d', $current->exp_month, $current->exp_year),
            ],
            'learner' => $request->integer('learner') ?: null,
            'tutor' => $request->integer('tutor') ?: null,
        ]);
    }

    public function store(StoreTestCardRequest $request, SaveTestCard $save): RedirectResponse
    {
        abort_unless(SaveTestCard::available(), 404);

        /** @var User $user */
        $user = $request->user();
        $save($user, $request->enum('card', TestCard::class));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test card saved.')]);

        $tutor = $request->integer('tutor');
        $learner = $request->integer('learner');

        if ($tutor > 0) {
            return to_route('weekly-slots.create', array_filter(['learner' => $learner ?: null, 'tutor' => $tutor]));
        }

        // Replacing the card from a learner's page (a slot paused for failed charges) returns there.
        $own = $learner > 0 && Learner::query()->whereKey($learner)->where('account_user_id', $user->id)->exists();

        return $own ? to_route('learners.show', $learner) : to_route('learners.index');
    }
}
