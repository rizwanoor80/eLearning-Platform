<?php

namespace App\Filament\Resources\ContentBlocks;

use App\Filament\Resources\ContentBlocks\Pages\EditContentBlock;
use App\Filament\Resources\ContentBlocks\Pages\ListContentBlocks;
use App\Filament\Resources\ContentBlocks\Schemas\ContentBlockForm;
use App\Filament\Resources\ContentBlocks\Tables\ContentBlocksTable;
use App\Models\ContentBlock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Homepage copy. The keys are fixed by the layout and seeded — the admin only
 * edits what each one says (no create, no delete).
 */
class ContentBlockResource extends Resource
{
    protected static ?string $model = ContentBlock::class;

    protected static ?string $navigationLabel = 'Site content';

    protected static ?string $modelLabel = 'content block';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ContentBlockForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContentBlocksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContentBlocks::route('/'),
            'edit' => EditContentBlock::route('/{record}/edit'),
        ];
    }
}
