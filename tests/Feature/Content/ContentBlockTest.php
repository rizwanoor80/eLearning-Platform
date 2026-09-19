<?php

use App\Enums\UserStatus;
use App\Filament\Resources\ContentBlocks\ContentBlockResource;
use App\Filament\Resources\ContentBlocks\Pages\EditContentBlock;
use App\Filament\Resources\ContentBlocks\Pages\ListContentBlocks;
use App\Models\AuditLog;
use App\Models\ContentBlock;
use App\Models\User;
use Database\Seeders\ContentBlockSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    test()->seed(ContentBlockSeeder::class);
});

function cbBlock(string $key): ContentBlock
{
    return ContentBlock::query()->where('key', $key)->firstOrFail();
}

/**
 * @return array<string, mixed>
 */
function cbHome(): array
{
    $content = [];
    test()->get('/')->assertOk()->assertInertia(function ($page) use (&$content) {
        $content = $page->toArray()['props']['content'];
    });

    return $content;
}

it('renders the seeded placeholder copy on the homepage', function () {
    $content = cbHome();

    expect($content['heroTitle'])->toBe('DRAFT — replace before launch')
        ->and($content['howItWorks'])->toHaveCount(3)
        ->and($content['faq'])->toHaveCount(1);
});

it('changes the homepage headline as soon as the admin saves it, with no deploy (CP2 box 6)', function () {
    Livewire::actingAs($this->admin)
        ->test(EditContentBlock::class, ['record' => cbBlock(ContentBlock::HERO_TITLE)->getRouteKey()])
        ->assertFormSet(['title_value' => 'DRAFT — replace before launch'])
        ->fillForm(['title_value' => 'Learn with a tutor who fits'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(cbHome()['heroTitle'])->toBe('Learn with a tutor who fits')
        ->and(cbBlock(ContentBlock::HERO_TITLE)->updated_by)->toBe($this->admin->id)
        ->and(AuditLog::query()->where('action', 'content_block.updated')->count())->toBe(1);
});

it('edits the how-it-works steps and the FAQ through the repeaters', function () {
    Livewire::actingAs($this->admin)
        ->test(EditContentBlock::class, ['record' => cbBlock(ContentBlock::HOW_IT_WORKS)->getRouteKey()])
        ->fillForm(['steps' => [['title' => 'Search', 'text' => 'Find **a tutor**.'], ['title' => 'Book', 'text' => 'Pick a time.']]])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::actingAs($this->admin)
        ->test(EditContentBlock::class, ['record' => cbBlock(ContentBlock::FAQ)->getRouteKey()])
        ->fillForm(['questions' => [['question' => 'Is it safe?', 'answer' => 'Yes.']]])
        ->call('save')
        ->assertHasNoFormErrors();

    $content = cbHome();
    expect(array_column($content['howItWorks'], 'title'))->toBe(['Search', 'Book'])
        ->and($content['howItWorks'][0]['html'])->toContain('<strong>a tutor</strong>')
        ->and($content['faq'][0]['question'])->toBe('Is it safe?');
});

it('renders without crashing when a block is missing, or holds malformed JSON (R30 #12, #21)', function () {
    ContentBlock::query()->delete();
    expect(cbHome())->toBe(['heroTitle' => '', 'heroText' => '', 'howItWorks' => [], 'faq' => []]);

    ContentBlock::query()->create(['key' => ContentBlock::HOW_IT_WORKS, 'body' => '{not json']);
    ContentBlock::query()->create(['key' => ContentBlock::FAQ, 'body' => '"just a string"']);

    expect(cbHome())->toMatchArray(['howItWorks' => [], 'faq' => []]);
});

it('skips malformed items and keeps the good ones (R30 #21)', function () {
    cbBlock(ContentBlock::FAQ)->update(['body' => json_encode([
        'oops', ['question' => ''], ['question' => 5], ['answer' => 'no question'], ['question' => 'Fine?', 'answer' => 'Fine.'],
    ])]);

    expect(array_column(cbHome()['faq'], 'question'))->toBe(['Fine?']);
});

it('opens a block with malformed JSON as an empty list instead of failing (R30 #21)', function () {
    cbBlock(ContentBlock::FAQ)->update(['body' => 'not json at all']);

    Livewire::actingAs($this->admin)
        ->test(EditContentBlock::class, ['record' => cbBlock(ContentBlock::FAQ)->getRouteKey()])
        ->assertOk();
});

it('never overwrites an edited block on a re-seed (R30 #12)', function () {
    cbBlock(ContentBlock::HERO_TITLE)->update(['body' => 'Edited headline']);

    test()->seed(ContentBlockSeeder::class);

    expect(cbBlock(ContentBlock::HERO_TITLE)->body)->toBe('Edited headline')->and(ContentBlock::query()->count())->toBe(4);
});

it('neutralises script and unsafe links in block copy (R30 #13)', function () {
    cbBlock(ContentBlock::HERO_TEXT)->update(['body' => 'Hi <script>alert(1)</script> [x](javascript:alert(2)) <img src=x onerror=alert(3)>']);
    cbBlock(ContentBlock::FAQ)->update(['body' => json_encode([['question' => 'Q', 'answer' => '<script>alert(4)</script>[y](javascript:alert(5))']])]);

    $content = cbHome();
    $html = $content['heroText'].$content['faq'][0]['html'];

    expect($html)->not->toContain('<script')->not->toContain('javascript:')->not->toContain('onerror')->not->toContain('<img');
});

it('lets only an active admin reach the content editor (R30 #15)', function () {
    test()->actingAs(User::factory()->tutor()->create())->get(ContentBlockResource::getUrl())->assertForbidden();
    test()->actingAs(User::factory()->create())->get(ContentBlockResource::getUrl())->assertForbidden();
    test()->actingAs(User::factory()->admin()->create(['status' => UserStatus::Suspended]))->get(ContentBlockResource::getUrl())->assertForbidden();

    Livewire::actingAs($this->admin)->test(ListContentBlocks::class)->assertCanSeeTableRecords(ContentBlock::all());
});
