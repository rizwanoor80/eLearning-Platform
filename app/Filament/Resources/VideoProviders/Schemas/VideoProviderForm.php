<?php

namespace App\Filament\Resources\VideoProviders\Schemas;

use App\Enums\VideoProviderCode;
use App\Models\VideoProvider;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Credential inputs are not model attributes (`secret_<key>`), so the form is never filled from the
 * stored value; they are password inputs whose state is cleared after every save. A blank input
 * keeps what is stored (see EditVideoProvider).
 */
class VideoProviderForm
{
    public const SECRET_PREFIX = 'secret_';

    public static function configure(Schema $schema): Schema
    {
        $components = [
            TextInput::make('name')->required()->maxLength(100),
            Toggle::make('supports_embed')
                ->label('Embeds in the lesson page')
                ->disabled(fn (?VideoProvider $record): bool => $record?->code !== VideoProviderCode::Fake->value),
            Toggle::make('supports_attendance_webhooks')
                ->label('Sends join/leave webhooks')
                ->disabled(fn (?VideoProvider $record): bool => $record?->code !== VideoProviderCode::Fake->value),
        ];

        foreach (self::allCredentialKeys() as $key) {
            $components[] = TextInput::make(self::SECRET_PREFIX.$key)
                ->label(ucfirst(str_replace('_', ' ', $key)))
                ->password()
                ->revealable(false)
                ->autocomplete('new-password')
                ->maxLength(500)
                ->visible(fn (?VideoProvider $record): bool => in_array($key, $record?->providerCode()?->credentialKeys() ?? [], true))
                ->placeholder(fn (?VideoProvider $record): string => $record?->hasCredential($key) ? 'Set — leave blank to keep' : 'Not set')
                ->helperText('Stored encrypted. It is never shown again after saving.');
        }

        return $schema->components($components);
    }

    /**
     * @return list<string>
     */
    private static function allCredentialKeys(): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn (VideoProviderCode $code): array => $code->credentialKeys(),
            VideoProviderCode::cases(),
        ))));
    }
}
