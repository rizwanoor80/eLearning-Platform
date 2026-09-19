<?php

namespace App\Filament\Resources\DocumentTypes\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DocumentTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Fixed once created: the permit type is found by its code (R36 g), and a code
                // is what seeders and code refer to.
                TextInput::make('code')
                    ->required()
                    ->maxLength(64)
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'regex' => 'Use lower-case letters, digits and underscores, starting with a letter.',
                        'unique' => 'A document type with this code already exists.',
                    ])
                    ->disabledOn('edit'),
                TextInput::make('name')
                    ->required(),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
                Toggle::make('required')
                    ->required(),
                Toggle::make('active')
                    ->required(),
                TextInput::make('sort')
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(32767)
                    ->default(0),
            ]);
    }
}
