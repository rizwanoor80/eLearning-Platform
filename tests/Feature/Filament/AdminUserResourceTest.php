<?php

use App\Actions\Admin\DisableAdminUser;
use App\Actions\RecordAuditLog;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('lists only admin users', function () {
    $otherAdmin = User::factory()->admin()->create();
    $tutor = User::factory()->tutor()->create();

    Livewire::actingAs($this->admin)
        ->test(ListUsers::class)
        ->assertCanSeeTableRecords([$this->admin, $otherAdmin])
        ->assertCanNotSeeTableRecords([$tutor]);
});

it('refuses a tutor and an account owner access to the admin-users resource', function () {
    foreach ([User::factory()->tutor()->create(), User::factory()->create()] as $user) {
        test()->actingAs($user)->get(ListUsers::getUrl())->assertForbidden();
    }
});

it('creates an admin user with the role forced server-side and writes an audit row', function () {
    Livewire::actingAs($this->admin)
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'New Admin',
            'email' => 'new-admin@project-elearning.test',
            'password' => 'a-long-enough-passphrase',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::query()->where('email', 'new-admin@project-elearning.test')->firstOrFail();
    expect($created->role)->toBe(Role::Admin)
        ->and($created->email_verified_at)->not->toBeNull();

    $log = AuditLog::query()->where('action', 'admin_user.created')->firstOrFail();
    expect($log->actor_user_id)->toBe($this->admin->id)
        ->and($log->subject_id)->toBe($created->id);
});

it('ignores a role smuggled into the create form', function () {
    Livewire::actingAs($this->admin)
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'Sneaky',
            'email' => 'sneaky@project-elearning.test',
            'password' => 'a-long-enough-passphrase',
        ])
        ->set('data.role', Role::Tutor->value)
        ->call('create');

    expect(User::query()->where('email', 'sneaky@project-elearning.test')->firstOrFail()->role)->toBe(Role::Admin);
});

it('disables an admin, which removes their admin panel access, and writes an audit row', function () {
    $other = User::factory()->admin()->create();

    (new DisableAdminUser(app(RecordAuditLog::class)))($this->admin, $other, 'Left the company.');

    $other->refresh();
    expect($other->status)->toBe(UserStatus::Suspended)
        ->and($other->suspended_reason)->toBe('Left the company.')
        ->and($other->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeFalse();
    expect(AuditLog::query()->where('action', 'admin_user.disabled')->exists())->toBeTrue();
});

it('will not let an admin disable themselves or the last active admin', function () {
    $disable = new DisableAdminUser(app(RecordAuditLog::class));

    expect(fn () => $disable($this->admin, $this->admin, 'x'))->toThrow(RuntimeException::class);

    $other = User::factory()->admin()->create();
    $other->forceFill(['status' => UserStatus::Suspended])->save();
    $lonely = $this->admin;
    expect(fn () => $disable($other, $lonely, 'x'))->toThrow(RuntimeException::class);
});

it('has no route that registers an admin', function () {
    $registrationRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->methods() !== ['GET', 'HEAD'] && str_contains($route->uri(), 'register'))
        ->map(fn ($route) => $route->uri());

    // Only the tutor registration route exists; nothing registers an admin.
    expect($registrationRoutes->values()->all())->each->not->toContain('admin');
});
