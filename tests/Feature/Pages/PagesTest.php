<?php

use App\Actions\Page\PublishPage;
use App\Enums\UserStatus;
use App\Filament\Resources\SitePages\Pages\EditSitePage;
use App\Filament\Resources\SitePages\Pages\ListSitePages;
use App\Filament\Resources\SitePages\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\SitePages\SitePageResource;
use App\Models\AuditLog;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\TutorProfile;
use App\Models\User;
use Database\Seeders\PageSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function pgHtml(string $uri): string
{
    $html = '';
    test()->get($uri)->assertOk()->assertInertia(function ($page) use (&$html) {
        $html = $page->toArray()['props']['html'];
    });

    return $html;
}

// ---- public routes (R30 #14) ----------------------------------------------------------------

it('serves the six public pages from the current version, with the tutor agreement under its dashed path', function () {
    test()->seed(PageSeeder::class);

    foreach (['terms', 'privacy', 'tutor-agreement', 'safeguarding', 'about', 'contact'] as $path) {
        test()->get('/'.$path)->assertOk()->assertInertia(fn ($page) => $page->component('pages/Show')->where('version', 1));
    }

    expect(pgHtml('/tutor-agreement'))->toContain('DRAFT — replace before launch');
});

it('404s a page whose row does not exist and leaves it out of the footer (R30 #14)', function () {
    test()->seed(PageSeeder::class);
    Page::query()->where('slug', 'about')->delete();

    test()->get('/about')->assertNotFound();
    test()->get('/terms')->assertInertia(fn ($page) => $page
        ->has('footerPages', 5)
        ->where('footerPages', collect(['terms' => 'Terms of service', 'privacy' => 'Privacy policy', 'tutor-agreement' => 'Tutor agreement', 'safeguarding' => 'Safeguarding', 'contact' => 'Contact us'])->map(fn ($title, $path) => ['title' => $title, 'href' => '/'.$path])->values()->all()));
});

it('lists the footer links in a fixed order with the titles from the database (R30 #14)', function () {
    test()->seed(PageSeeder::class);
    app(PublishPage::class)($this->admin, Page::query()->where('slug', 'privacy')->firstOrFail(), 'Your privacy', 'New body.');

    test()->get('/')->assertInertia(fn ($page) => $page->where('footerPages', [
        ['title' => 'Terms of service', 'href' => '/terms'],
        ['title' => 'Your privacy', 'href' => '/privacy'],
        ['title' => 'Tutor agreement', 'href' => '/tutor-agreement'],
        ['title' => 'Safeguarding', 'href' => '/safeguarding'],
        ['title' => 'About us', 'href' => '/about'],
        ['title' => 'Contact us', 'href' => '/contact'],
    ]));
});

// ---- publishing (R30 #9) --------------------------------------------------------------------

it('publishes a new version through the admin editor and shows it on the public route at once (R30 #9)', function () {
    test()->seed(PageSeeder::class);
    $page = Page::query()->where('slug', 'terms')->firstOrFail();

    Livewire::actingAs($this->admin)
        ->test(EditSitePage::class, ['record' => $page->getRouteKey()])
        ->fillForm(['title' => 'Terms v2', 'body' => "# New terms\n\nBe kind."])
        ->call('save')
        ->assertNotified('Published');

    $page->refresh();
    expect($page->version)->toBe(2)
        ->and($page->title)->toBe('Terms v2')
        ->and($page->updated_by)->toBe($this->admin->id)
        ->and(PageVersion::query()->where('page_id', $page->id)->orderBy('version')->pluck('version')->all())->toBe([1, 2])
        ->and(AuditLog::query()->where('action', 'page.published')->count())->toBe(1);

    expect(pgHtml('/terms'))->toContain('<h1>New terms</h1>')->toContain('Be kind.');
});

it('keeps the previous version readable in the admin (R30 #9)', function () {
    test()->seed(PageSeeder::class);
    $page = Page::query()->where('slug', 'terms')->firstOrFail();
    app(PublishPage::class)($this->admin, $page, 'Terms v2', 'Second body.');

    Livewire::actingAs($this->admin)
        ->test(VersionsRelationManager::class, ['ownerRecord' => $page->fresh(), 'pageClass' => EditSitePage::class])
        ->assertCanSeeTableRecords($page->versions()->get());

    expect($page->versions()->where('version', 1)->value('body'))->toBe('DRAFT — replace before launch');
});

it('publishes nothing when the content is unchanged (R30 #9)', function () {
    test()->seed(PageSeeder::class);
    $page = Page::query()->where('slug', 'terms')->firstOrFail();

    Livewire::actingAs($this->admin)
        ->test(EditSitePage::class, ['record' => $page->getRouteKey()])
        ->call('save')
        ->assertNotified('No changes to publish');

    expect($page->fresh()->version)->toBe(1)->and(PageVersion::query()->where('page_id', $page->id)->count())->toBe(1);
});

it('numbers successive publishes one after another and refuses a duplicate version row (R30 #9)', function () {
    test()->seed(PageSeeder::class);
    $page = Page::query()->where('slug', 'terms')->firstOrFail();

    expect(app(PublishPage::class)($this->admin, $page, 'T', 'b2')->version)->toBe(2)
        ->and(app(PublishPage::class)($this->admin, $page, 'T', 'b3')->version)->toBe(3);

    // The unique (page_id, version) index is the backstop behind the row lock.
    expect(fn () => DB::transaction(fn () => PageVersion::query()->create(['page_id' => $page->id, 'version' => 3, 'title' => 'x', 'body' => 'y'])))
        ->toThrow(QueryException::class);
});

it('lets only an active admin reach the pages editor (R30 #15)', function () {
    test()->seed(PageSeeder::class);

    test()->actingAs(User::factory()->tutor()->create())->get(SitePageResource::getUrl())->assertForbidden();
    test()->actingAs(User::factory()->create())->get(SitePageResource::getUrl())->assertForbidden();
    test()->actingAs(User::factory()->admin()->create(['status' => UserStatus::Suspended]))->get(SitePageResource::getUrl())->assertForbidden();
    test()->actingAs($this->admin)->get(SitePageResource::getUrl())->assertOk();

    Livewire::actingAs($this->admin)->test(ListSitePages::class)->assertCanSeeTableRecords(Page::all());
});

// ---- seeding (R30 #11) ----------------------------------------------------------------------

it('never overwrites an edited page on a re-seed and adds only the missing pages (R30 #11)', function () {
    $agreement = Page::factory()->create(['slug' => 'tutor_agreement', 'title' => 'Tutor agreement', 'body' => 'Edited by an admin', 'version' => 1]);
    $agreement->versions()->create(['version' => 1, 'title' => 'Tutor agreement', 'body' => 'Edited by an admin']);

    test()->seed(PageSeeder::class);

    expect(Page::query()->count())->toBe(6)
        ->and($agreement->fresh()->body)->toBe('Edited by an admin')
        ->and($agreement->fresh()->version)->toBe(1)
        ->and(PageVersion::query()->count())->toBe(6);

    app(PublishPage::class)($this->admin, Page::query()->where('slug', 'terms')->firstOrFail(), 'Terms', 'Live edit');
    test()->seed(PageSeeder::class);

    expect(Page::query()->where('slug', 'terms')->value('body'))->toBe('Live edit')
        ->and(Page::query()->where('slug', 'terms')->value('version'))->toBe(2)
        ->and(PageVersion::query()->count())->toBe(7);
});

// ---- the tutor agreement (R30 #10) ----------------------------------------------------------

it('keeps a tutor’s accepted agreement version when a newer one is published (R30 #10)', function () {
    Page::factory()->create(['slug' => 'tutor_agreement', 'title' => 'Tutor agreement', 'version' => 1]);
    $tutor = agreementReadyTutor();
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1])->assertRedirect(route('tutor.onboarding'));

    app(PublishPage::class)($this->admin, Page::query()->where('slug', 'tutor_agreement')->firstOrFail(), 'Tutor agreement', 'Version two text.');

    expect(TutorProfile::query()->where('user_id', $tutor->id)->value('agreement_version'))->toBe(1);

    $next = agreementReadyTutor();
    test()->actingAs($next)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 2])->assertRedirect(route('tutor.onboarding'));
    expect(TutorProfile::query()->where('user_id', $next->id)->value('agreement_version'))->toBe(2);
});

it('refuses an acceptance of a stale or missing version and re-renders the new one (R30 #10)', function () {
    Page::factory()->create(['slug' => 'tutor_agreement', 'title' => 'Tutor agreement', 'version' => 1]);
    $tutor = agreementReadyTutor();
    app(PublishPage::class)($this->admin, Page::query()->where('slug', 'tutor_agreement')->firstOrFail(), 'Tutor agreement', 'Version two text.');

    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 1])->assertSessionHasErrors('version');
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true])->assertSessionHasErrors('version');
    test()->actingAs($tutor)->post(route('tutor.onboarding.agreement'), ['accepted' => true, 'version' => 99])->assertSessionHasErrors('version');
    expect(TutorProfile::query()->where('user_id', $tutor->id)->value('agreement_version'))->toBeNull();

    test()->actingAs($tutor)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page->where('agreement.current_version', 2)->where('agreement.body', 'Version two text.'));
});

// ---- markdown safety (R30 #13) --------------------------------------------------------------

it('neutralises script, inline handlers and javascript links in a public page (R30 #13)', function () {
    test()->seed(PageSeeder::class);
    $body = "Hello <script>alert(1)</script>\n\n<img src=x onerror=alert(2)>\n\n[click](javascript:alert(3))\n\n[fine](https://example.com/ok)\n\n<a href=\"javascript:alert(4)\">raw</a>";
    app(PublishPage::class)($this->admin, Page::query()->where('slug', 'about')->firstOrFail(), 'About', $body);

    $html = pgHtml('/about');

    expect($html)->not->toContain('<script')->not->toContain('onerror')->not->toContain('javascript:')->not->toContain('<img')
        ->and($html)->toContain('https://example.com/ok');
});
