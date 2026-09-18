<?php

use App\Filament\Resources\DocumentTypes\Pages\CreateDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\EditDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\ListDocumentTypes;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('lists document types for an admin', function () {
    $type = DocumentType::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(ListDocumentTypes::class)
        ->assertCanSeeTableRecords([$type]);
});

it('lets an admin create a document type', function () {
    Livewire::actingAs($this->admin)
        ->test(CreateDocumentType::class)
        ->fillForm([
            'code' => 'reference_letter',
            'name' => 'Reference letter',
            'description' => 'A reference from a previous employer.',
            'required' => true,
            'active' => true,
            'sort' => 5,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(DocumentType::query()->where('code', 'reference_letter')->exists())->toBeTrue();
});

it('lets an admin deactivate a document type', function () {
    $type = DocumentType::factory()->create(['active' => true]);

    Livewire::actingAs($this->admin)
        ->test(EditDocumentType::class, ['record' => $type->getRouteKey()])
        ->fillForm(['active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($type->fresh()->active)->toBeFalse();
});

it('refuses a non-admin access to the document types resource', function () {
    $tutor = User::factory()->tutor()->create();

    $response = test()->actingAs($tutor)->get(ListDocumentTypes::getUrl());

    $response->assertForbidden();
});

it('audits creating and changing a document type', function () {
    Livewire::actingAs($this->admin)
        ->test(CreateDocumentType::class)
        ->fillForm([
            'code' => 'audited_type', 'name' => 'Audited', 'description' => 'd',
            'required' => true, 'active' => true, 'sort' => 1,
        ])
        ->call('create');
    $type = DocumentType::query()->where('code', 'audited_type')->firstOrFail();

    Livewire::actingAs($this->admin)
        ->test(EditDocumentType::class, ['record' => $type->getRouteKey()])
        ->fillForm(['active' => false])
        ->call('save');

    $created = AuditLog::query()->where('action', 'document_type.created')->firstOrFail();
    $updated = AuditLog::query()->where('action', 'document_type.updated')->firstOrFail();
    expect($created->actor_user_id)->toBe($this->admin->id)
        ->and($updated->before)->toBe(['active' => true])
        ->and($updated->after)->toBe(['active' => false]);
});

it('offers no delete on a document type', function () {
    $type = DocumentType::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(EditDocumentType::class, ['record' => $type->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});
