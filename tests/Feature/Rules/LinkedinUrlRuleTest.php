<?php

use App\Models\TutorProfile;
use App\Models\User;
use App\Rules\LinkedinUrlRule;

function linkedinRuleFails(mixed $value): bool
{
    $failed = false;
    (new LinkedinUrlRule)->validate('linkedin_url', $value, function () use (&$failed) {
        $failed = true;
    });

    return $failed;
}

it('accepts linkedin.com and its subdomains over https', function (string $url) {
    expect(linkedinRuleFails($url))->toBeFalse();
})->with([
    'https://linkedin.com/in/test-tutor',
    'https://www.linkedin.com/in/test-tutor',
    'https://ae.linkedin.com/in/test-tutor',
]);

it('rejects the evil.com path-spoofing attack named in the rule\'s own docblock', function () {
    expect(linkedinRuleFails('https://evil.com/linkedin.com'))->toBeTrue();
});

it('rejects the linkedin.com.evil.com subdomain-spoofing attack named in the rule\'s own docblock', function () {
    expect(linkedinRuleFails('https://linkedin.com.evil.com'))->toBeTrue();
});

it('rejects a host that merely contains linkedin.com as a substring', function () {
    expect(linkedinRuleFails('https://notlinkedin.com/in/test-tutor'))->toBeTrue();
});

it('rejects plain http even on the real host', function () {
    expect(linkedinRuleFails('http://linkedin.com/in/test-tutor'))->toBeTrue();
});

it('rejects a non-string value', function () {
    expect(linkedinRuleFails(['https://linkedin.com']))->toBeTrue();
});

it('rejects a value with no host at all', function () {
    expect(linkedinRuleFails('not-a-url'))->toBeTrue();
});

it('accepts a valid LinkedIn URL posted to the onboarding linkedin step', function () {
    $tutor = User::factory()->tutor()->create();

    $response = $this->actingAs($tutor)->post(route('tutor.onboarding.linkedin'), [
        'linkedin_url' => 'https://www.linkedin.com/in/test-tutor',
    ]);

    $response->assertRedirect(route('tutor.onboarding'));
    $response->assertSessionDoesntHaveErrors('linkedin_url');
    expect(TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail()->linkedin_url)
        ->toBe('https://www.linkedin.com/in/test-tutor');
});

it('refuses the evil.com spoofing attack posted to the onboarding linkedin step, saving nothing', function () {
    $tutor = User::factory()->tutor()->create();

    $response = $this->actingAs($tutor)->post(route('tutor.onboarding.linkedin'), [
        'linkedin_url' => 'https://evil.com/linkedin.com',
    ]);

    $response->assertSessionHasErrors('linkedin_url');
    expect(TutorProfile::query()->where('user_id', $tutor->id)->first()?->linkedin_url)->toBeNull();
});

it('accepts a null linkedin_url, clearing a previously-set value', function () {
    $tutor = User::factory()->tutor()->create();
    TutorProfile::factory()->for($tutor, 'user')->create(['linkedin_url' => 'https://www.linkedin.com/in/test-tutor']);

    $response = $this->actingAs($tutor)->post(route('tutor.onboarding.linkedin'), [
        'linkedin_url' => null,
    ]);

    $response->assertRedirect(route('tutor.onboarding'));
    $response->assertSessionDoesntHaveErrors('linkedin_url');
    expect(TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail()->linkedin_url)->toBeNull();
});
