<?php

namespace App\Filament\Resources\PayoutMethods\Schemas;

use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PayoutMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100),
            Toggle::make('is_active')
                ->label('Offered to tutors')
                ->helperText('A tutor who already chose a method that is switched off is listed as "cannot be paid yet" until they choose another.'),
            TagsInput::make('countries')
                ->label('Countries')
                ->placeholder('AE')
                ->helperText('Two-letter country codes (ISO 3166-1 alpha-2), e.g. AE, PK. Leave empty to offer the method in every country.')
                ->nestedRecursiveRules(['string', 'regex:/^\s*[A-Za-z]{2}\s*$/'])
                ->dehydrateStateUsing(fn (?array $state): array => collect($state ?? [])
                    ->map(fn ($code): string => strtoupper(trim((string) $code)))
                    ->unique()
                    ->values()
                    ->all()),
        ]);
    }
}
