<?php

namespace App\Filament\Resources\SitePages\Schemas;

use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SitePageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')->required()->maxLength(255),
                MarkdownEditor::make('body')
                    ->required()
                    ->helperText('Markdown. Raw HTML is removed. Publishing creates a new version and goes live immediately; use the editor Preview tab to check first.')
                    ->columnSpanFull(),
            ]);
    }
}
