<?php

use App\Actions\Ops\IssueOpsStatusToken;
use App\Actions\Ops\RevokeOpsStatusToken;
use App\Actions\Ops\UpdateOpsStatusAllowList;
use App\Exceptions\OpsStatusTokenException;
use App\Filament\Pages\ManageOperations;
use App\Models\AuditLog;
use App\Models\OpsStatusToken;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery\MockInterface;

/**
 * R193: Admin → Settings → Operations. The token is generated, rotated and revoked here, shown once, and
 * stored as a hash only. What matters most below: the plaintext reaches exactly one place (the Livewire
 * property), and the log, the audit trail and the table never see it.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();

    // The endpoint is called in some of these tests; Horizon's Redis repositories are not what they are about.
    foreach ([MasterSupervisorRepository::class, SupervisorRepository::class] as $repository) {
        test()->mock($repository, fn (MockInterface $mock) => $mock->shouldReceive('all')->andReturn([]));
    }
});

function opsGenerate(User $admin): array
{
    $page = Livewire::actingAs($admin)->test(ManageOperations::class)->callAction('generate')->assertHasNoActionErrors();

    return [$page, $page->get('revealedToken')];
}

function opsCall(string $token): int
{
    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/ops/status')->getStatusCode();
}

it('refuses a non-admin the operations page', function () {
    test()->actingAs(User::factory()->tutor()->create())->get(ManageOperations::getUrl())->assertForbidden();
});

it('shows an admin the page with no token yet and offers only Generate', function () {
    Livewire::actingAs($this->admin)
        ->test(ManageOperations::class)
        ->assertOk()
        ->assertSee('There is no active token')
        ->assertActionVisible('generate')
        ->assertActionHidden('rotate')
        ->assertActionHidden('revoke');
});

it('generates a 64-character token, reveals it once, and keeps only its hash', function () {
    [$page, $plain] = opsGenerate($this->admin);

    expect($plain)->toBeString()->toHaveLength(64);

    $row = OpsStatusToken::query()->sole();

    expect($row->token_hash)->toBe(hash('sha256', $plain))
        ->and($row->revoked_at)->toBeNull()
        ->and($row->created_by)->toBe($this->admin->id)
        ->and(DB::table('ops_status_tokens')->get()->toJson())->not->toContain($plain)
        ->and(opsCall($plain))->toBe(200);

    $page->assertSee($plain)->assertSeeHtml('data-testid="revealed-token"')
        ->assertActionHidden('generate')->assertActionVisible('rotate')->assertActionVisible('revoke');
});

it('hides the token for good once dismissed, and a fresh page load never shows it', function () {
    [$page, $plain] = opsGenerate($this->admin);

    $page->call('dismissToken')->assertSet('revealedToken', null)->assertDontSee($plain);

    Livewire::actingAs($this->admin)->test(ManageOperations::class)->assertSet('revealedToken', null)->assertDontSee($plain);
});

it('does not let a client set the revealed token', function () {
    expect(fn () => Livewire::actingAs($this->admin)->test(ManageOperations::class)->set('revealedToken', 'forged'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('never lets the token, or its hash, reach the audit trail, the log or a notification', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = json_encode([$event->level, $event->message, $event->context]);
    });

    [$page, $plain] = opsGenerate($this->admin);
    $hash = hash('sha256', $plain);

    opsCall($plain);                    // a successful read (audited)
    opsCall(str_repeat('x', 64));       // a rejection (logged)
    opsCall($plain.'tampered');         // another rejection

    $everything = collect([
        'audit' => AuditLog::query()->get()->toJson(),
        'log' => implode("\n", $logged),
        'notifications' => json_encode(session()->get('filament.notifications')),
    ]);

    // Names the place on failure: an empty list means none of the three holds either string.
    expect($everything->filter(fn (string $text) => str_contains($text, $plain) || str_contains($text, $hash))->keys()->all())->toBe([])
        ->and($logged)->not->toBeEmpty();
});

it('rotates: the old token stops at once, the new one works, and there is still exactly one active', function () {
    [, $old] = opsGenerate($this->admin);
    $oldId = OpsStatusToken::query()->sole()->id;

    $page = Livewire::actingAs($this->admin)->test(ManageOperations::class)->callAction('rotate')->assertHasNoActionErrors();
    $new = $page->get('revealedToken');

    expect($new)->toHaveLength(64)->not->toBe($old)
        ->and(opsCall($old))->toBe(404)
        ->and(opsCall($new))->toBe(200)
        ->and(OpsStatusToken::query()->count())->toBe(2)
        ->and(OpsStatusToken::query()->active()->count())->toBe(1);

    $oldRow = OpsStatusToken::query()->findOrFail($oldId);
    $audit = AuditLog::query()->where('action', 'ops_status_token.rotated')->sole();

    expect($oldRow->revoked_at)->not->toBeNull()
        ->and($oldRow->revoked_by)->toBe($this->admin->id)
        ->and($audit->before)->toBe(['replaced_token_id' => $oldId])
        ->and($audit->actor_user_id)->toBe($this->admin->id);
});

it('revokes: the endpoint answers 404 to everyone and the row is kept', function () {
    [, $plain] = opsGenerate($this->admin);

    Livewire::actingAs($this->admin)
        ->test(ManageOperations::class)
        ->callAction('revoke')
        ->assertHasNoActionErrors()
        ->assertSet('revealedToken', null)
        ->assertActionVisible('generate');

    expect(opsCall($plain))->toBe(404)
        ->and(OpsStatusToken::query()->count())->toBe(1)
        ->and(OpsStatusToken::current())->toBeNull();

    $audit = AuditLog::query()->where('action', 'ops_status_token.revoked')->sole();
    expect($audit->actor_user_id)->toBe($this->admin->id);
});

it('does nothing, and records nothing, when revoking with no active token', function () {
    expect(app(RevokeOpsStatusToken::class)($this->admin))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'ops_status_token.revoked')->count())->toBe(0);

    app(IssueOpsStatusToken::class)($this->admin);

    expect(app(RevokeOpsStatusToken::class)($this->admin))->toBeTrue()
        ->and(app(RevokeOpsStatusToken::class)($this->admin))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'ops_status_token.revoked')->count())->toBe(1);
});

it('refuses every action to a non-admin', function () {
    $tutor = User::factory()->tutor()->create();

    expect(fn () => app(IssueOpsStatusToken::class)($tutor))->toThrow(OpsStatusTokenException::class)
        ->and(fn () => app(RevokeOpsStatusToken::class)($tutor))->toThrow(OpsStatusTokenException::class)
        ->and(fn () => app(UpdateOpsStatusAllowList::class)($tutor, ['203.0.113.1']))->toThrow(OpsStatusTokenException::class)
        ->and(OpsStatusToken::query()->count())->toBe(0);
});

it('saves the allow-list on the active token, audits the change, and applies it at once', function () {
    [, $plain] = opsGenerate($this->admin);

    Livewire::actingAs($this->admin)
        ->test(ManageOperations::class)
        ->fillForm(['allowed_ips' => "203.0.113.0/24\n198.51.100.7, 198.51.100.7"])
        ->call('saveAllowList')
        ->assertNotified('Allow-list saved');

    expect(OpsStatusToken::current()->allowed_ips)->toBe(['203.0.113.0/24', '198.51.100.7']);

    test()->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->withHeaders(['Authorization' => 'Bearer '.$plain])->getJson('/ops/status')->assertNotFound();
    test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->withHeaders(['Authorization' => 'Bearer '.$plain])->getJson('/ops/status')->assertOk();

    $audit = AuditLog::query()->where('action', 'ops_status_token.allow_list_changed')->sole();
    expect($audit->before)->toBe(['allowed_ips' => null])
        ->and($audit->after)->toBe(['allowed_ips' => ['203.0.113.0/24', '198.51.100.7']]);
});

it('rejects an invalid allow-list entry and leaves the saved one alone', function () {
    opsGenerate($this->admin);

    Livewire::actingAs($this->admin)
        ->test(ManageOperations::class)
        ->fillForm(['allowed_ips' => "203.0.113.0/24\nnot-an-address"])
        ->call('saveAllowList')
        ->assertNotified();

    expect(OpsStatusToken::current()->allowed_ips)->toBeNull()
        ->and(AuditLog::query()->where('action', 'ops_status_token.allow_list_changed')->count())->toBe(0);
});

it('refuses to save an allow-list when there is no token, and does not audit an unchanged one', function () {
    expect(fn () => app(UpdateOpsStatusAllowList::class)($this->admin, ['203.0.113.1']))->toThrow(OpsStatusTokenException::class);

    app(IssueOpsStatusToken::class)($this->admin, ['203.0.113.1']);
    app(UpdateOpsStatusAllowList::class)($this->admin, ['203.0.113.1']);

    expect(AuditLog::query()->where('action', 'ops_status_token.allow_list_changed')->count())->toBe(0);
});

it('can carry an allow-list into the new token when it is generated', function () {
    $issued = app(IssueOpsStatusToken::class)($this->admin, ['198.51.100.0/24']);

    expect($issued->token->allowed_ips)->toBe(['198.51.100.0/24'])
        ->and(AuditLog::query()->where('action', 'ops_status_token.issued')->sole()->after)->toBe(['allowed_ips' => ['198.51.100.0/24']]);
});

it('cannot hold two active tokens: the database refuses the second', function () {
    OpsStatusToken::factory()->create();

    // Inside its own savepoint: a failed statement would otherwise abort the test's whole transaction on PostgreSQL.
    expect(fn () => DB::transaction(fn () => OpsStatusToken::factory()->create()))->toThrow(UniqueConstraintViolationException::class);

    OpsStatusToken::factory()->revoked()->count(3)->create();

    expect(OpsStatusToken::query()->active()->count())->toBe(1);
});

it('never exposes the hash when a token row is serialised', function () {
    $row = OpsStatusToken::factory()->create();

    expect($row->toArray())->not->toHaveKey('token_hash');
});
