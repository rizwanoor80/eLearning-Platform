<?php

namespace App\Filament\Resources\Reviews;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Reviews\Pages\ListReviews;
use App\Filament\Resources\Reviews\Tables\ReviewsTable;
use App\Models\Review;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * R136: admin can unpublish a review (with a required note, audited) and republish it. There is no
 * create, edit or delete here — a review is only ever written by `SubmitReview`, and
 * `SetReviewPublished` is the only door to changing its published state. Stays available while
 * `features.reviews` is off: the toggle hides the public pages, not the admin queue or the data.
 */
class ReviewResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = Review::class;

    protected static ?string $navigationLabel = 'Reviews';

    protected static ?string $modelLabel = 'review';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ReviewsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviews::route('/'),
        ];
    }
}
