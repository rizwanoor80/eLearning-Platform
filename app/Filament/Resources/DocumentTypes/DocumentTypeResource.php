<?php

namespace App\Filament\Resources\DocumentTypes;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\DocumentTypes\Pages\CreateDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\EditDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\ListDocumentTypes;
use App\Filament\Resources\DocumentTypes\Schemas\DocumentTypeForm;
use App\Filament\Resources\DocumentTypes\Tables\DocumentTypesTable;
use App\Models\DocumentType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DocumentTypeResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = DocumentType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    // RequiresActiveAdmin does not cover create (R149(f)): this resource has a create page and,
    // without a DocumentType policy, Filament's no-policy default is allow, so a disabled admin
    // would otherwise still be able to create one through an already-open Livewire tab.
    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return DocumentTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentTypes::route('/'),
            'create' => CreateDocumentType::route('/create'),
            'edit' => EditDocumentType::route('/{record}/edit'),
        ];
    }
}
