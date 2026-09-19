<?php

use App\Actions\Match\CloseMatchRequest;
use App\Actions\Match\SuggestTutors;
use App\Enums\BudgetTier;
use App\Enums\LevelTier;
use App\Enums\MatchRequestStatus;
use App\Enums\SettingGroup;
use App\Enums\TutorProfileStatus;
use App\Enums\UserStatus;
use App\Events\Match\MatchSuggestionsReady;
use App\Exceptions\MatchRequestException;
use App\Filament\Resources\MatchRequests\MatchRequestResource;
use App\Filament\Resources\MatchRequests\Pages\ListMatchRequests;
use App\Filament\Resources\MatchRequests\Pages\ViewMatchRequest;
use App\Mail\Match\MatchSuggestionsMail;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\MatchRequest;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Models\YearGroup;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
    $this->admin = User::factory()->admin()->create();
});

function mrTutor(Curriculum $curriculum, array $profile = [], string $name = 'Layla Hassan'): TutorProfile
{
    $user = User::factory()->tutor()->create(['name' => $name]);
    $tutor = TutorProfile::factory()->approved()->create(array_merge(['user_id' => $user->id, 'hourly_rate' => 10000, 'headline' => 'Patient maths tutor'], $profile));
    TutorSubject::factory()->create(['tutor_profile_id' => $tutor->id, 'curriculum_id' => $curriculum->id]);

    return $tutor;
}

/**
 * @return array<string, mixed>
 */
function mrPayload(Learner $learner, Curriculum $curriculum, array $overrides = []): array
{
    return array_merge([
        'learner_id' => $learner->id,
        'curriculum_id' => $curriculum->id,
        'subject_id' => Subject::factory()->create()->id,
        'year_group_id' => YearGroup::query()->firstOrCreate(['curriculum_id' => $curriculum->id, 'code' => 'y8'], ['label' => 'Year 8', 'sort' => 8, 'level_tier' => LevelTier::LowerSecondary])->id,
        'goals' => 'Fractions and confidence.',
        'preferred_times' => 'Weekday evenings',
        'budget_tier' => 'mid',
    ], $overrides);
}

// ---- the parent side ------------------------------------------------------------------------

it('lets a parent submit a request for their own learner as an open request', function () {
    $parent = User::factory()->create();
    $curriculum = Curriculum::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);

    test()->actingAs($parent)->post(route('match-requests.store'), mrPayload($learner, $curriculum))->assertRedirect(route('match-requests.index'));

    $request = MatchRequest::query()->sole();
    expect($request->status)->toBe(MatchRequestStatus::Open)
        ->and($request->account_user_id)->toBe($parent->id)
        ->and($request->learner_id)->toBe($learner->id)
        ->and($request->budget_tier)->toBe(BudgetTier::Mid)
        ->and($request->suggested_tutor_ids)->toBeNull();
});

it('keeps the request’s own snapshot when the learner is edited later (R30 #1)', function () {
    $parent = User::factory()->create();
    $curriculum = Curriculum::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    $payload = mrPayload($learner, $curriculum);
    test()->actingAs($parent)->post(route('match-requests.store'), $payload);

    $other = Curriculum::factory()->create();
    $learner->fill(['year_group_id' => YearGroup::factory()->create(['curriculum_id' => $other->id])->id, 'curriculum_id' => $other->id, 'display_name' => 'Renamed'])->save();
    YearGroup::query()->findOrFail($payload['year_group_id'])->update(['label' => 'Renamed year']);  // R33: a relabel must not reach the snapshot

    $request = MatchRequest::query()->sole();
    expect($request->year_group)->toBe('Year 8')->and($request->curriculum_id)->toBe($curriculum->id);
});

it('still shows a request whose learner was later deleted, to the parent and the admin (R30 #2)', function () {
    $parent = User::factory()->create();
    $request = MatchRequest::factory()->create(['account_user_id' => $parent->id]);
    $name = $request->learner->display_name;
    $request->learner->delete();

    test()->actingAs($parent)->get(route('match-requests.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('requests', 1)->where('requests.0.learner', $name));

    Livewire::actingAs($this->admin)->test(ListMatchRequests::class)->assertCanSeeTableRecords([$request]);
    Livewire::actingAs($this->admin)->test(ViewMatchRequest::class, ['record' => $request->getRouteKey()])->assertOk();
});

it('makes an adult student choose a curriculum: the self-learner has none (R30 #3)', function () {
    $adult = User::factory()->create();
    $self = Learner::factory()->selfLearner()->create(['account_user_id' => $adult->id]);
    $curriculum = Curriculum::factory()->create();

    test()->actingAs($adult)->post(route('match-requests.store'), mrPayload($self, $curriculum, ['curriculum_id' => '']))
        ->assertSessionHasErrors('curriculum_id');
    expect(MatchRequest::query()->count())->toBe(0);

    test()->actingAs($adult)->post(route('match-requests.store'), mrPayload($self, $curriculum))->assertSessionHasNoErrors();
    expect(MatchRequest::query()->sole()->curriculum_id)->toBe($curriculum->id);
});

it('rejects another account’s learner and a deleted learner (R30 #4)', function () {
    $parent = User::factory()->create();
    $curriculum = Curriculum::factory()->create();
    $foreign = Learner::factory()->create();
    $gone = Learner::factory()->create(['account_user_id' => $parent->id]);
    $gone->delete();

    test()->actingAs($parent)->post(route('match-requests.store'), mrPayload($foreign, $curriculum))->assertSessionHasErrors('learner_id');
    test()->actingAs($parent)->post(route('match-requests.store'), mrPayload($gone, $curriculum))->assertSessionHasErrors('learner_id');
    expect(MatchRequest::query()->count())->toBe(0);
});

it('validates the form fields', function () {
    $parent = User::factory()->create();

    test()->actingAs($parent)->post(route('match-requests.store'), ['budget_tier' => 'gold'])
        ->assertSessionHasErrors(['learner_id', 'curriculum_id', 'subject_id', 'year_group_id', 'goals', 'budget_tier']);
});

it('never lets the client set status or the owning account (R30 #8)', function () {
    $parent = User::factory()->create();
    $stranger = User::factory()->create();
    $curriculum = Curriculum::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);

    test()->actingAs($parent)->post(route('match-requests.store'), mrPayload($learner, $curriculum, [
        'status' => 'closed', 'account_user_id' => $stranger->id, 'handled_by' => $stranger->id, 'suggested_tutor_ids' => [1],
    ]));

    $request = MatchRequest::query()->sole();
    expect($request->status)->toBe(MatchRequestStatus::Open)
        ->and($request->account_user_id)->toBe($parent->id)
        ->and($request->handled_by)->toBeNull()
        ->and($request->suggested_tutor_ids)->toBeNull()
        ->and((new MatchRequest(['status' => 'closed', 'handled_by' => 5]))->getAttributes())->toBe([]);
});

it('shows a parent only their own requests (R30 #16) and keeps tutors and admins out', function () {
    $parent = User::factory()->create();
    MatchRequest::factory()->create(['account_user_id' => $parent->id]);
    MatchRequest::factory()->create();

    test()->post(route('match-requests.store'), [])->assertRedirect(route('login'));
    test()->actingAs($parent)->get(route('match-requests.index'))->assertInertia(fn ($page) => $page->has('requests', 1));

    foreach ([User::factory()->tutor()->create(), $this->admin] as $user) {
        test()->actingAs($user)->get(route('match-requests.index'))->assertForbidden();
        test()->actingAs($user)->post(route('match-requests.store'), [])->assertForbidden();
    }

});

// ---- the admin side: suggest / close --------------------------------------------------------

it('suggests 1–3 bookable tutors, audits it and queues the parent’s email', function () {
    $curriculum = Curriculum::factory()->create();
    $request = MatchRequest::factory()->create(['curriculum_id' => $curriculum->id]);
    $a = mrTutor($curriculum);
    $b = mrTutor($curriculum, name: 'Omar Farooq');

    Livewire::actingAs($this->admin)
        ->test(ViewMatchRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('suggest', ['tutor_ids' => [$a->id, $b->id]])
        ->assertNotified();

    $request->refresh();
    expect($request->status)->toBe(MatchRequestStatus::Suggested)
        ->and($request->suggested_tutor_ids)->toBe([$a->id, $b->id])
        ->and($request->handled_by)->toBe($this->admin->id)
        ->and($request->suggested_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'match_request.suggested')->count())->toBe(1);

    Mail::assertQueued(MatchSuggestionsMail::class, fn (MatchSuggestionsMail $mail) => $mail->hasTo($request->account->email) && count($mail->cards) === 2);
});

it('refuses a non-bookable tutor smuggled into the suggestion and changes nothing (R30 #5, #18)', function (array $profile) {
    $curriculum = Curriculum::factory()->create();
    $request = MatchRequest::factory()->create(['curriculum_id' => $curriculum->id]);
    $good = mrTutor($curriculum);
    $bad = mrTutor($curriculum, $profile);

    expect(fn () => app(SuggestTutors::class)($this->admin, $request, [$good->id, $bad->id]))->toThrow(MatchRequestException::class);

    expect($request->fresh()->status)->toBe(MatchRequestStatus::Open)->and($request->fresh()->suggested_tutor_ids)->toBeNull();
    Mail::assertNothingQueued();
})->with([
    'suspended' => [['status' => TutorProfileStatus::Suspended]],
    'draft' => [['status' => TutorProfileStatus::Draft]],
    'expired permit' => [['permit_expires_at' => now()->subDay()]],
]);

it('enforces one to three suggested tutors', function () {
    $curriculum = Curriculum::factory()->create();
    $request = MatchRequest::factory()->create(['curriculum_id' => $curriculum->id]);
    $tutors = collect(range(1, 4))->map(fn () => mrTutor($curriculum));

    expect(fn () => app(SuggestTutors::class)($this->admin, $request, []))->toThrow(MatchRequestException::class)
        ->and(fn () => app(SuggestTutors::class)($this->admin, $request, $tutors->pluck('id')->all()))->toThrow(MatchRequestException::class);

    app(SuggestTutors::class)($this->admin, $request, [$tutors[0]->id, $tutors[0]->id]); // duplicates collapse to one
    expect($request->fresh()->suggested_tutor_ids)->toBe([$tutors[0]->id]);
});

it('replaces the list on a re-suggestion and refuses a closed request (R30 #6)', function () {
    $curriculum = Curriculum::factory()->create();
    $request = MatchRequest::factory()->create(['curriculum_id' => $curriculum->id]);
    $first = mrTutor($curriculum);
    $second = mrTutor($curriculum);

    app(SuggestTutors::class)($this->admin, $request, [$first->id]);
    app(SuggestTutors::class)($this->admin, $request->fresh(), [$second->id]);
    expect($request->fresh()->suggested_tutor_ids)->toBe([$second->id])->and($request->fresh()->status)->toBe(MatchRequestStatus::Suggested);

    app(CloseMatchRequest::class)($this->admin, $request->fresh());
    expect($request->fresh()->status)->toBe(MatchRequestStatus::Closed)
        ->and(fn () => app(SuggestTutors::class)($this->admin, $request->fresh(), [$first->id]))->toThrow(MatchRequestException::class)
        ->and(fn () => app(CloseMatchRequest::class)($this->admin, $request->fresh()))->toThrow(MatchRequestException::class);
});

it('closes an open request from the view page and audits it', function () {
    $request = MatchRequest::factory()->create();

    Livewire::actingAs($this->admin)->test(ViewMatchRequest::class, ['record' => $request->getRouteKey()])->callAction('close');

    expect($request->fresh()->status)->toBe(MatchRequestStatus::Closed)
        ->and(AuditLog::query()->where('action', 'match_request.closed')->count())->toBe(1);
});

it('offers only bookable tutors who teach the request’s curriculum in the picker (R30 #18)', function () {
    $curriculum = Curriculum::factory()->create();
    $other = Curriculum::factory()->create();
    $request = MatchRequest::factory()->create(['curriculum_id' => $curriculum->id]);
    $ok = mrTutor($curriculum);
    mrTutor($curriculum, ['status' => TutorProfileStatus::Suspended]);
    mrTutor($other);

    expect(array_keys(ViewMatchRequest::bookableOptions($request)))->toBe([$ok->id]);
});

// ---- the email ------------------------------------------------------------------------------

it('drops a tutor who stopped being bookable before the email is sent (R30 #5)', function () {
    $curriculum = Curriculum::factory()->create();
    $request = MatchRequest::factory()->create(['curriculum_id' => $curriculum->id]);
    $stays = mrTutor($curriculum);
    $goes = mrTutor($curriculum, name: 'Omar Farooq');

    // The mail is sent by the listener; suspend one tutor after the admin picked them, before it runs.
    Mail::fake();
    $request->forceFill(['status' => MatchRequestStatus::Suggested, 'suggested_tutor_ids' => [$stays->id, $goes->id]])->save();
    $goes->forceFill(['status' => TutorProfileStatus::Suspended])->save();

    event(new MatchSuggestionsReady($request));

    Mail::assertQueued(MatchSuggestionsMail::class, fn (MatchSuggestionsMail $mail) => array_column($mail->cards, 'id') === [$stays->id]);
});

it('sends nothing and audits it when no suggested tutor is bookable any more (R30 #5)', function () {
    $curriculum = Curriculum::factory()->create();
    $request = MatchRequest::factory()->create(['curriculum_id' => $curriculum->id]);
    $only = mrTutor($curriculum);
    $request->forceFill(['status' => MatchRequestStatus::Suggested, 'suggested_tutor_ids' => [$only->id]])->save();
    $only->forceFill(['status' => TutorProfileStatus::Suspended])->save();

    event(new MatchSuggestionsReady($request));

    Mail::assertNothingQueued();
    expect(AuditLog::query()->where('action', 'match_request.suggestions_dropped')->count())->toBe(1);
});

it('builds the email from the public card only: no surname, contact, permit or goals', function () {
    $curriculum = Curriculum::factory()->create();
    $request = MatchRequest::factory()->create(['curriculum_id' => $curriculum->id, 'goals' => 'PRIVATE-GOALS-TEXT']);
    $tutor = mrTutor($curriculum, ['permit_number' => 'PMT-SECRET-123']);
    $tutor->user->forceFill(['email' => 'layla.private@example.test', 'phone' => '+971500000000'])->save();

    app(SuggestTutors::class)($this->admin, $request, [$tutor->id]);

    Mail::assertQueued(MatchSuggestionsMail::class, function (MatchSuggestionsMail $mail) use ($tutor) {
        $html = $mail->render();
        expect($html)->toContain('Layla')->toContain('AED 100.00')->toContain('AED 50.00')->toContain(route('tutors.show', $tutor->id))
            ->not->toContain('Hassan')->not->toContain('layla.private@example.test')->not->toContain('+971500000000')
            ->not->toContain('PMT-SECRET-123')->not->toContain('PRIVATE-GOALS-TEXT');
        expect(array_keys($mail->cards[0]))->toEqualCanonicalizing(['id', 'name', 'headline', 'rate', 'trial_price', 'rating_avg', 'rating_count', 'subjects', 'next_slot']);

        return true;
    });
});

it('shows a parent only the suggestions that are still bookable (R30 #5)', function () {
    $curriculum = Curriculum::factory()->create();
    $parent = User::factory()->create();
    $stays = mrTutor($curriculum);
    $goes = mrTutor($curriculum);
    MatchRequest::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => $curriculum->id])
        ->forceFill(['status' => MatchRequestStatus::Suggested, 'suggested_tutor_ids' => [$stays->id, $goes->id]])->save();
    $goes->forceFill(['status' => TutorProfileStatus::Suspended])->save();

    test()->actingAs($parent)->get(route('match-requests.index'))
        ->assertInertia(fn ($page) => $page->has('requests.0.suggestions', 1)->where('requests.0.suggestions.0.id', $stays->id));
});

// ---- the toggle -----------------------------------------------------------------------------

it('404s the parent routes, hides the admin resource and keeps the data while the toggle is off (R30 #7)', function () {
    $parent = User::factory()->create();
    $request = MatchRequest::factory()->create(['account_user_id' => $parent->id]);

    Settings::set('match_requests', false, SettingGroup::Features);

    test()->actingAs($parent)->get(route('match-requests.index'))->assertNotFound();
    test()->actingAs($parent)->get(route('match-requests.create'))->assertNotFound();
    test()->actingAs($parent)->post(route('match-requests.store'), [])->assertNotFound();
    test()->actingAs($parent)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('features.match_requests', false));
    test()->actingAs($this->admin)->get(MatchRequestResource::getUrl())->assertForbidden();
    expect(MatchRequestResource::canAccess())->toBeFalse();
    expect(MatchRequest::query()->whereKey($request->id)->exists())->toBeTrue();

    Settings::set('match_requests', true, SettingGroup::Features);

    test()->actingAs($parent)->get(route('match-requests.index'))->assertOk()->assertInertia(fn ($page) => $page->has('requests', 1));
    test()->actingAs($this->admin)->get(MatchRequestResource::getUrl())->assertOk();
});

it('still sends a suggestions email that was already on its way when the toggle went off (R30 #19)', function () {
    $curriculum = Curriculum::factory()->create();
    $request = MatchRequest::factory()->create(['curriculum_id' => $curriculum->id]);
    $tutor = mrTutor($curriculum);
    $request->forceFill(['status' => MatchRequestStatus::Suggested, 'suggested_tutor_ids' => [$tutor->id]])->save();

    Settings::set('match_requests', false, SettingGroup::Features);
    event(new MatchSuggestionsReady($request));

    Mail::assertQueued(MatchSuggestionsMail::class);
});

// ---- admins, accounts -----------------------------------------------------------------------

it('keeps a disabled admin out of the queue, and the handler stays recorded when they are disabled later (R30 #15, #20)', function () {
    $curriculum = Curriculum::factory()->create();
    $request = MatchRequest::factory()->create(['curriculum_id' => $curriculum->id]);
    $tutor = mrTutor($curriculum);
    app(SuggestTutors::class)($this->admin, $request, [$tutor->id]);

    $this->admin->forceFill(['status' => UserStatus::Suspended])->save();

    expect($request->fresh()->handled_by)->toBe($this->admin->id)
        ->and($request->fresh()->suggested_tutor_ids)->toBe([$tutor->id]);
    test()->actingAs($this->admin)->get(MatchRequestResource::getUrl())->assertForbidden();
});

it('deletes a parent’s requests with their account without an error (R30 #17)', function () {
    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id]);
    MatchRequest::factory()->create(['account_user_id' => $parent->id, 'learner_id' => $learner->id]);

    test()->actingAs($parent)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

    expect(User::query()->whereKey($parent->id)->exists())->toBeFalse()
        ->and(MatchRequest::query()->count())->toBe(0)
        ->and(Learner::withTrashed()->count())->toBe(0);
});
