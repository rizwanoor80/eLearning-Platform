<?php

use App\Actions\Admin\AnonymizeUser;
use App\Actions\Admin\ClearAnonymisedTutorBankDetails;
use App\Enums\LessonStatus;
use App\Exceptions\UserDeletionException;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Ledger\LedgerService;

const ABD_IBAN = 'AE070331234567890123456';

/** A tutor with bank details and, when `$released`, one lesson whose money was released `$daysAgo` days ago. */
function abdTutor(bool $released, int $daysAgo = 60): TutorProfile
{
    $tutor = TutorProfile::factory()->approved()->create([
        'country' => 'AE',
        'payout_method' => 'bank_transfer',
        'bank_iban' => ABD_IBAN,
        'bank_swift' => 'ABCDAEAA',
        'bank_verified_at' => now(),
        'user_id' => User::factory()->tutor(),
    ]);

    if ($released) {
        $lesson = Lesson::factory()->withStatus(LessonStatus::Completed)->startingAt(now()->subDays($daysAgo)->startOfHour())->create(['tutor_profile_id' => $tutor->id]);
        app(LedgerService::class)->hold($lesson);
        app(LedgerService::class)->release($lesson);
        Lesson::allowingStatusWrites(fn () => $lesson->forceFill(['status' => LessonStatus::CompletedReported])->save());
    }

    return $tutor;
}

function abdBank(TutorProfile $tutor): array
{
    $row = TutorProfile::query()->whereKey($tutor->id)->first();

    return array_map(fn (string $field) => $row->getAttribute($field), TutorProfile::BANK_FIELDS);
}

it('clears the bank details with the account when the tutor is owed nothing', function () {
    $admin = User::factory()->admin()->create();
    $tutor = abdTutor(released: false);

    app(AnonymizeUser::class)($admin, $tutor->user);

    expect(abdBank($tutor))->each->toBeNull();
    $audit = AuditLog::query()->where('action', 'user.anonymized')->sole();
    expect($audit->after['bank_details'])->toBe('cleared')
        ->and(json_encode($audit->toArray()))->not->toContain(ABD_IBAN);
});

it('keeps the bank details while released money is owed, and clears everything else', function (int $daysAgo) {
    $admin = User::factory()->admin()->create();
    $tutor = abdTutor(released: true, daysAgo: $daysAgo);

    app(AnonymizeUser::class)($admin, $tutor->user);

    $row = TutorProfile::query()->find($tutor->id);
    expect($row->bank_iban)->toBe(ABD_IBAN)
        ->and($row->bank_swift)->toBe('ABCDAEAA')
        ->and($row->payout_method)->toBeNull()
        ->and($row->payout_email)->toBeNull()
        ->and(AuditLog::query()->where('action', 'user.anonymized')->sole()->after['bank_details'])->toBe('kept_money_owed');
})->with(['available' => 60, 'still in the dispute window' => 0]);

it('records the decision, deletes the kept details and writes no value to the audit trail', function () {
    $admin = User::factory()->admin()->create();
    $tutor = abdTutor(released: true);
    app(AnonymizeUser::class)($admin, $tutor->user);
    $tutor = $tutor->fresh();

    expect(ClearAnonymisedTutorBankDetails::applies($tutor))->toBeTrue();

    expect(app(ClearAnonymisedTutorBankDetails::class)($admin, $tutor, 'Paid by hand, reference in the finance sheet.'))->toBeTrue();

    expect(abdBank($tutor))->each->toBeNull()
        ->and(ClearAnonymisedTutorBankDetails::applies($tutor->fresh()))->toBeFalse();

    $audit = AuditLog::query()->where('action', 'tutor.bank_details_cleared')->sole();
    expect($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->after['note'])->toBe('Paid by hand, reference in the finance sheet.')
        ->and($audit->after['owed_fils'])->toBeGreaterThan(0)
        ->and($audit->after['fields_cleared'])->toContain('bank_iban', 'bank_name')
        ->and(json_encode($audit->toArray()))->not->toContain(ABD_IBAN)->not->toContain('ABCDAEAA');
});

it('does nothing the second time', function () {
    $admin = User::factory()->admin()->create();
    $tutor = abdTutor(released: true);
    app(AnonymizeUser::class)($admin, $tutor->user);

    app(ClearAnonymisedTutorBankDetails::class)($admin, $tutor->fresh(), 'first');

    expect(app(ClearAnonymisedTutorBankDetails::class)($admin, $tutor->fresh(), 'second'))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'tutor.bank_details_cleared')->count())->toBe(1);
});

it('refuses a non-admin and a live account', function () {
    $admin = User::factory()->admin()->create();
    $live = abdTutor(released: true);

    expect(fn () => app(ClearAnonymisedTutorBankDetails::class)($admin, $live, 'x'))->toThrow(UserDeletionException::class, 'not anonymised')
        ->and(ClearAnonymisedTutorBankDetails::applies($live))->toBeFalse()
        ->and($live->fresh()->bank_iban)->toBe(ABD_IBAN);

    app(AnonymizeUser::class)($admin, $live->user);

    expect(fn () => app(ClearAnonymisedTutorBankDetails::class)(User::factory()->create(), $live->fresh(), 'x'))->toThrow(UserDeletionException::class, 'Only an admin')
        ->and($live->fresh()->bank_iban)->toBe(ABD_IBAN);
});
