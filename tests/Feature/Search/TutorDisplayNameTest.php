<?php

use App\Enums\MatchRequestStatus;
use App\Mail\Match\MatchSuggestionsMail;
use App\Models\Curriculum;
use App\Models\MatchRequest;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Services\Search\TutorPresenter;
use Illuminate\Support\Facades\Mail;

// R32: one accessor, first word only, Unicode-whitespace safe (R30 #1, #14).
it('derives the public name from the first word of the account name', function (string $accountName, string $expected) {
    $tutor = new TutorProfile;
    $tutor->setRelation('user', (new User)->forceFill(['name' => $accountName]));

    expect($tutor->displayName())->toBe($expected);
})->with([
    'two words' => ['Layla Hassan', 'Layla'],
    'single word' => ['Layla', 'Layla'],
    'leading no-break space' => ["\u{00A0}Layla Hassan", 'Layla'],
    'leading tab and newline' => ["\t\nLayla Hassan", 'Layla'],
    'no-break space between names' => ["Layla\u{00A0}Hassan", 'Layla'],
    'ideographic space' => ["Layla\u{3000}Hassan", 'Layla'],
    'many spaces' => ['   Layla     Ann   Hassan  ', 'Layla'],
    'accented name' => ['Élodie Martin', 'Élodie'],
    'arabic name' => ['ليلى حسن', 'ليلى'],
    'hyphenated first name' => ['Anne-Marie Dupont', 'Anne-Marie'],
    'empty falls back' => ['', 'Tutor'],
    'only whitespace falls back' => ["  \u{00A0} ", 'Tutor'],
]);

it('is derived on every read, so a rename shows at once', function () {
    $user = User::factory()->tutor()->create(['name' => 'Layla Hassan']);
    $tutor = TutorProfile::factory()->approved()->create(['user_id' => $user->id]);
    expect($tutor->fresh()->displayName())->toBe('Layla');

    $user->forceFill(['name' => 'Noor Ali'])->save();

    expect($tutor->fresh()->displayName())->toBe('Noor');
});

it('uses the same name on the search card, the profile and the suggestions email (R30 #14)', function () {
    $curriculum = Curriculum::factory()->create();
    $user = User::factory()->tutor()->create(['name' => "\u{00A0}Layla\u{00A0}Hassan"]);
    $tutor = TutorProfile::factory()->approved()->create(['user_id' => $user->id, 'hourly_rate' => 10000]);
    TutorSubject::factory()->create(['tutor_profile_id' => $tutor->id, 'curriculum_id' => $curriculum->id]);
    $tutor->load('user', 'tutorSubjects');

    $card = app(TutorPresenter::class)->card($tutor, []);
    $profile = app(TutorPresenter::class)->profile($tutor, [], false);

    expect($card['name'])->toBe('Layla')->and($profile['name'])->toBe('Layla');

    Mail::fake();
    $request = MatchRequest::factory()->create(['curriculum_id' => $curriculum->id]);
    $request->forceFill(['status' => MatchRequestStatus::Suggested])->save();

    $html = (new MatchSuggestionsMail($request, [$card]))->render();
    expect($html)->toContain('Layla')->not->toContain('Hassan');
});
