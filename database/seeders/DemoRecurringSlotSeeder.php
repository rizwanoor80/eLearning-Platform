<?php

namespace Database\Seeders;

use App\Models\AvailabilityRule;
use App\Models\Learner;
use App\Models\PaymentMethod;
use App\Models\RecurringSlot;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL ONLY (wired from `DatabaseSeeder` under `environment('local')`): one demo parent with a
 * fake-gateway test card, one demo learner, one approved demo tutor and one active weekly slot
 * between them, so the CP4 screens and `recurring:*` jobs have something to act on. Never runs on
 * rehearsal or production — those must not gain fake cards. Idempotent: it does nothing if the
 * demo parent already exists, and runs in one transaction so a half-seeded pair cannot trip that
 * guard. The demo tutor's only availability is Tuesday 17:00–18:00 and the slot occupies it, so the
 * tutor shows no bookable hour — intended, not a bug. Addresses are `example.test`; no real identity
 * (HOW-WE-WORK §8).
 */
class DemoRecurringSlotSeeder extends Seeder
{
    public const PARENT_EMAIL = 'demo.parent@example.test';

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seed();
        });
    }

    private function seed(): void
    {
        if (User::query()->where('email', self::PARENT_EMAIL)->exists()) {
            return;
        }

        $parent = User::factory()->create(['name' => 'Demo Parent', 'email' => self::PARENT_EMAIL]);
        PaymentMethod::factory()->create(['account_user_id' => $parent->id]);

        $tutor = TutorProfile::factory()->approved()->approvable()->create();
        $tutor->user->forceFill(['name' => 'Demo Tutor', 'email' => 'demo.tutor@example.test'])->save();
        $taught = $tutor->tutorSubjects()->firstOrFail();

        AvailabilityRule::factory()->create(['tutor_profile_id' => $tutor->id, 'weekday' => 2, 'start_time' => '17:00', 'end_time' => '18:00', 'timezone' => 'Asia/Dubai']);

        $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'display_name' => 'Demo Learner', 'curriculum_id' => $taught->curriculum_id]);

        $today = Date::today();

        RecurringSlot::factory()->create([
            'learner_id' => $learner->id,
            'tutor_profile_id' => $tutor->id,
            'curriculum_id' => $taught->curriculum_id,
            'subject_id' => $taught->subject_id,
            'weekday' => 2,
            'start_time' => '17:00',
            'timezone' => 'Asia/Dubai',
            'starts_on' => $today,
            'price' => $tutor->hourly_rate,
            'generated_until' => $today->copy()->subDay(),
            'created_by_user_id' => $parent->id,
        ]);
    }
}
