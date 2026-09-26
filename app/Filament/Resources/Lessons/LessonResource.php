<?php

namespace App\Filament\Resources\Lessons;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Filament\Resources\Lessons\Tables\LessonsTable;
use App\Models\Lesson;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The admin lesson list (PRD §12). CP6 7c gives it one action, "Mark provider failure"; force-cancel
 * and force-complete are later checkpoints'. No create, edit or view page: a lesson's status is
 * changed only through its Actions and the state machine.
 */
class LessonResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = Lesson::class;

    protected static ?string $navigationLabel = 'Lessons';

    protected static ?string $modelLabel = 'lesson';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return LessonsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLessons::route('/'),
        ];
    }
}
