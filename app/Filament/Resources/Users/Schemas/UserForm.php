<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    /**
     * No role field: the role is set server-side in CreateUser, never from
     * form input.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique('users', 'email')
                    ->maxLength(255),
                TextInput::make('password')
                    ->password()
                    ->required()
                    ->minLength(12),
            ]);
    }
}
