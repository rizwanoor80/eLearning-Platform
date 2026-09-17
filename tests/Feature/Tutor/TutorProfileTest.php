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

it('bookable() requires approved status and a permit expiring strictly after today', function () {
    $ok = TutorProfile::factory()->approved()->create();
    TutorProfile::factory()->create();
    TutorProfile::factory()->approved()->withExpiredPermit()->create();
    TutorProfile::factory()->approved()->withPermitExpiringToday()->create();

    expect(TutorProfile::bookable()->pluck('id')->all())->toBe([$ok->id]);
});
