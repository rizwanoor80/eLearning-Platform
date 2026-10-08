<?php

namespace App\Actions\Tutor;

use App\Models\TutorProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * R185: extracted from `TutorOnboardingController::storeAvailability()` so the Filament admin
 * edit-on-behalf action reuses the wizard's overlap check and the same save, as `SaveTutorSubjects`
 * does for subjects. Weekly windows are stored in the tutor's own timezone (the one documented
 * exception to UTC storage, invariant 4): `users.timezone` at the time of saving. Input shape
 * (weekday 0–6, `H:i` times, end after start) is validated by the caller; the cross-row overlap
 * rule runs here.
 */
class SaveTutorAvailability
{
    /**
     * @param  array<int, array{weekday: int, start_time: string, end_time: string}>  $rules
     * @param  array<int, array{date: string, start_time: string, end_time: string, type: string}>|null  $exceptions  null leaves the tutor's date exceptions alone (the admin editor); an array (even empty) replaces them (the wizard)
     *
     * @throws ValidationException when two windows on the same weekday overlap
     */
    public function __invoke(TutorProfile $profile, array $rules, ?array $exceptions = null): void
    {
        $rules = collect($rules);

        // The wizard's request class checks this too; the admin form cannot express it per row.
        foreach ($rules as $rule) {
            if ($rule['end_time'] <= $rule['start_time']) {
                throw ValidationException::withMessages([
                    'rules' => 'Each availability window must end after it starts.',
                ]);
            }
        }

        foreach ($rules->groupBy('weekday') as $weekday => $dayRules) {
            $sorted = $dayRules->sortBy('start_time')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                if ($sorted[$i]['start_time'] < $sorted[$i - 1]['end_time']) {
                    throw ValidationException::withMessages([
                        'rules' => "Availability rules for weekday {$weekday} overlap.",
                    ]);
                }
            }
        }

        $timezone = $profile->user->timezone;

        DB::transaction(function () use ($profile, $rules, $exceptions, $timezone): void {
            $profile->availabilityRules()->delete();

            foreach ($rules as $rule) {
                $profile->availabilityRules()->create([
                    'weekday' => $rule['weekday'],
                    'start_time' => $rule['start_time'],
                    'end_time' => $rule['end_time'],
                    'timezone' => $timezone,
                ]);
            }

            if ($exceptions !== null) {
                $profile->availabilityExceptions()->delete();

                foreach ($exceptions as $exception) {
                    $profile->availabilityExceptions()->create($exception);
                }
            }
        });
    }

    /**
     * The tutor's weekly windows as plain `H:i` rows, for the audit trail and the admin form.
     *
     * @return array<int, array{weekday: int, start_time: string, end_time: string}>
     */
    public static function snapshot(TutorProfile $profile): array
    {
        return $profile->availabilityRules()
            ->orderBy('weekday')->orderBy('start_time')
            ->get(['weekday', 'start_time', 'end_time'])
            ->map(fn ($rule): array => [
                'weekday' => (int) $rule->weekday,
                'start_time' => substr((string) $rule->start_time, 0, 5),
                'end_time' => substr((string) $rule->end_time, 0, 5),
            ])
            ->all();
    }
}
