<?php

namespace App\Actions\Tutor;

use App\Models\TutorProfile;
use App\Services\Tutors\TutorRateBands;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * R173(a) item 3: extracted from `TutorOnboardingController::storeRate()` so the Filament admin
 * edit-on-behalf action (R171) enforces the exact same band rules as the wizard, rather than a
 * second, drifting copy.
 */
class SetTutorRate
{
    public function __construct(private TutorRateBands $rateBands) {}

    /**
     * @throws ValidationException when the tutor's subjects have no single satisfiable band, or
     *                             the rate falls outside the band for the highest level taught.
     *
     * Aborts with a bare 409 (not a validation error) when the tutor has no subjects yet, so
     * there is no band to validate against — preserved from the wizard's own precondition
     * (`storeSubjects` must run first) rather than converted into a field-level message.
     */
    public function __invoke(TutorProfile $profile, Money $rate): void
    {
        $band = $this->rateBands->bandFor($profile);
        abort_if($band === null, 409, 'Add at least one subject before setting your rate.');

        if ($band['conflicting'] !== []) {
            throw ValidationException::withMessages([
                'hourly_rate' => sprintf(
                    'No single rate satisfies every curriculum you teach at this level — %s have non-overlapping bands. Adjust your subjects or contact support.',
                    implode(' and ', $band['conflicting']),
                ),
            ]);
        }

        if ($rate->toFils() < $band['min'] || $rate->toFils() > $band['max']) {
            throw ValidationException::withMessages([
                'hourly_rate' => sprintf(
                    'Your rate must be between %s and %s for the highest level you teach.',
                    Money::fils($band['min'])->format(),
                    Money::fils($band['max'])->format(),
                ),
            ]);
        }

        $profile->update(['hourly_rate' => $rate]);
    }
}
