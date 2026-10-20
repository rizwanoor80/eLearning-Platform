<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\CreateCheckoutIntent;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lessons\StoreLessonBookingRequest;
use App\Http\Requests\Payments\CreateCheckoutIntentRequest;
use App\Models\User;
use App\Services\Payments\NoActivePaymentGateway;
use App\Support\Payments\ActiveStripe;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * R248: the one call the booking page makes to Stripe's checkout before it asks the browser to confirm
 * the card. JSON in, JSON out; it answers 404 when Stripe is not the active gateway, so the fake driver's
 * environments never reach it, and 422 with a message the page shows when the booking cannot be paid for.
 */
class CheckoutIntentController extends Controller
{
    public function store(CreateCheckoutIntentRequest $request, ActiveStripe $stripe, CreateCheckoutIntent $create): JsonResponse
    {
        abort_unless($stripe->isActive(), 404);

        /** @var User $user */
        $user = $request->user();
        /** @var array{learner_id: int, tutor_id: int, starts_at: string, quote_token: string} $data */
        $data = $request->validated();

        // Same strict parse as the booking form: a value that does not format back to itself is not a time.
        $startsAt = CarbonImmutable::createFromFormat('!'.StoreLessonBookingRequest::STARTS_AT_FORMAT, $data['starts_at'], 'UTC');

        if ($startsAt->format(StoreLessonBookingRequest::STARTS_AT_FORMAT) !== $data['starts_at']) {
            return response()->json(['message' => __('That time is not valid.')], 422);
        }

        try {
            $intent = $create($user, [...$data, 'starts_at' => $startsAt]);
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (NoActivePaymentGateway) {
            return response()->json(['message' => __('Booking is not available yet.')], 422);
        }

        return response()->json(['payment_intent_id' => $intent['id'], 'client_secret' => $intent['client_secret']]);
    }
}
