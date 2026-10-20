<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\CreateCardSetupIntent;
use App\Actions\Payments\SaveStripeCard;
use App\Actions\Payments\SaveTestCard;
use App\Enums\TestCard;
use App\Exceptions\PaymentCaptureException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StoreStripeCardRequest;
use App\Http\Requests\Payments\StoreTestCardRequest;
use App\Models\Learner;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payments\NoActivePaymentGateway;
use App\Support\Payments\ActiveStripe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The parent's "Add a card" page (R100, R248), driver-neutral: under the fake gateway it is the test-card
 * picker (no typed digits, 404 outside the fake-gateway environments, R107); under Stripe it mounts
 * Stripe Elements for a SetupIntent, so the card number goes from the browser to Stripe and never
 * through this server. With neither gateway active every route here is a 404.
 */
class PaymentMethodController extends Controller
{
    public function create(Request $request, ActiveStripe $stripe): Response
    {
        $fake = SaveTestCard::available();
        $key = $stripe->publishableKey();

        abort_unless($fake || $key !== null, 404);

        /** @var User $user */
        $user = $request->user();
        $current = PaymentMethod::query()->where('account_user_id', $user->id)->first();

        return Inertia::render('payment-methods/Create', [
            'driver' => $fake ? 'fake' : 'stripe',
            'stripe' => $fake ? null : ['publishable_key' => $key],
            'cards' => array_map(
                fn (TestCard $card): array => ['value' => $card->value, 'label' => $card->label().' (…'.$card->last4().')'],
                $fake ? TestCard::cases() : [],
            ),
            'current' => $current === null ? null : [
                'brand' => $current->brand,
                'last4' => $current->last4,
                'expires' => sprintf('%02d/%d', $current->exp_month, $current->exp_year),
                'notice' => $current->expiryNotice(),
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

        return $this->afterSaving($request, $user);
    }

    /** The SetupIntent the page's Payment Element confirms; 404 unless Stripe is the active gateway. */
    public function setupIntent(Request $request, ActiveStripe $stripe, CreateCardSetupIntent $create): JsonResponse
    {
        abort_unless($stripe->isActive(), 404);

        /** @var User $user */
        $user = $request->user();

        try {
            $intent = $create($user);
        } catch (NoActivePaymentGateway) {
            return response()->json(['message' => __('Cards cannot be added yet.')], 422);
        }

        return response()->json(['setup_intent_id' => $intent['id'], 'client_secret' => $intent['client_secret']]);
    }

    public function storeStripe(StoreStripeCardRequest $request, ActiveStripe $stripe, SaveStripeCard $save): RedirectResponse
    {
        abort_unless($stripe->isActive(), 404);

        /** @var User $user */
        $user = $request->user();

        try {
            $save($user, (string) $request->validated('setup_intent_id'));
        } catch (PaymentCaptureException) {
            return back()->withErrors(['setup_intent_id' => __('We could not confirm that card. Please try again.')]);
        } catch (NoActivePaymentGateway) {
            return back()->withErrors(['setup_intent_id' => __('Cards cannot be added yet.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Card saved.')]);

        return $this->afterSaving($request, $user);
    }

    private function afterSaving(Request $request, User $user): RedirectResponse
    {
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
