<?php

use App\Models\TutorProfile;
use Illuminate\Support\Facades\DB;

it('stores bank_iban encrypted and exposes only the last four (CP1 box 7, model half)', function () {
    $plain = 'AE070331234567890123456';
    $profile = TutorProfile::factory()->create(['bank_iban' => $plain]);

    $raw = DB::table('tutor_profiles')->where('id', $profile->id)->value('bank_iban');

    expect($raw)->not->toBe($plain)
        ->and($raw)->not->toContain($plain)
        ->and($profile->fresh()->bank_iban)->toBe($plain)
        ->and($profile->bankIbanMasked())->toBe(str_repeat('•', strlen($plain) - 4).substr($plain, -4));
});

it('bookable() requires approved status and, when a permit is on file, one expiring strictly after today', function () {
    $ok = TutorProfile::factory()->bookable()->create();
    TutorProfile::factory()->create();
    TutorProfile::factory()->bookable()->withExpiredPermit()->create();
    TutorProfile::factory()->bookable()->withPermitExpiringToday()->create();

    expect(TutorProfile::bookable()->pluck('id')->all())->toBe([$ok->id]);
});

it('bookable() accepts an approved tutor with no permit on file at all (R170: the permit is optional)', function () {
    $noPermit = TutorProfile::factory()->bookable()->create(['permit_number' => null, 'permit_expires_at' => null]);

    expect(TutorProfile::bookable()->pluck('id')->all())->toBe([$noPermit->id]);
});

it('bookable() never includes a draft tutor, permit or no permit', function () {
    TutorProfile::factory()->create(['permit_number' => null, 'permit_expires_at' => null]);
    TutorProfile::factory()->create();

    expect(TutorProfile::bookable()->pluck('id')->all())->toBe([]);
});
