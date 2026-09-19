<?php

namespace App\Filament\Resources\ContentBlocks\Schemas;

use App\Models\ContentBlock;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * One form, four shapes: the fields shown depend on the block's key. The
 * helper fields (`title_value`, `markdown_value`, `steps`, `questions`) are
 * mapped to and from the single `body` column by EditContentBlock.
 */
class ContentBlockForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')->disabled()->dehydrated(false),

                TextInput::make('title_value')
                    ->label('Headline')
                    ->required()
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('key') === ContentBlock::HERO_TITLE),

                MarkdownEditor::make('markdown_value')
                    ->disableToolbarButtons(['attachFiles'])
                    ->label('Text')
                    ->required()
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('key') === ContentBlock::HERO_TEXT),

                Repeater::make('steps')
                    ->label('Steps')
                    ->schema([
                        TextInput::make('title')->required()->maxLength(255),
                        Textarea::make('text')->rows(3),
                    ])
                    ->minItems(1)
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('key') === ContentBlock::HOW_IT_WORKS),

                Repeater::make('questions')
                    ->label('Questions')
                    ->schema([
                        TextInput::make('question')->required()->maxLength(255),
                        Textarea::make('answer')->rows(3),
                    ])
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => $get('key') === ContentBlock::FAQ),
            ]);
    }
}
