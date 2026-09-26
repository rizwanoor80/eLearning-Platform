<?php

namespace App\Filament\Resources\VideoProviders;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\VideoProviders\Pages\EditVideoProvider;
use App\Filament\Resources\VideoProviders\Pages\ListVideoProviders;
use App\Filament\Resources\VideoProviders\Schemas\VideoProviderForm;
use App\Filament\Resources\VideoProviders\Tables\VideoProvidersTable;
use App\Models\VideoProvider;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The video-provider registry (PRD §12, invariant 16). The set of rows is fixed by migration — no
 * create, no delete — and credentials are write-only: the edit form never receives a stored value.
 */
class VideoProviderResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = VideoProvider::class;

    protected static ?string $navigationLabel = 'Video providers';

    protected static ?string $modelLabel = 'video provider';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return VideoProviderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VideoProvidersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVideoProviders::route('/'),
            'edit' => EditVideoProvider::route('/{record}/edit'),
        ];
    }
}
