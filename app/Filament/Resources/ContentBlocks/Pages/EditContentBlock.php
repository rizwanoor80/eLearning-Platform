<?php

namespace App\Filament\Resources\ContentBlocks\Pages;

use App\Filament\Concerns\AuditsResourceChanges;
use App\Filament\Resources\ContentBlocks\ContentBlockResource;
use App\Models\ContentBlock;
use App\Support\BudgetTierLabels;
use Filament\Resources\Pages\EditRecord;
use JsonException;

class EditContentBlock extends EditRecord
{
    use AuditsResourceChanges;

    protected static string $resource = ContentBlockResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $body = (string) ($data['body'] ?? '');

        if (in_array($data['key'] ?? null, BudgetTierLabels::keys(), true)) {
            return [...$data, 'title_value' => $body];
        }

        return match ($data['key'] ?? null) {
            ContentBlock::HERO_TITLE => [...$data, 'title_value' => $body],
            ContentBlock::HERO_TEXT => [...$data, 'markdown_value' => $body],
            ContentBlock::HOW_IT_WORKS => [...$data, 'steps' => $this->decode($body)],
            ContentBlock::FAQ => [...$data, 'questions' => $this->decode($body)],
            default => $data,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var ContentBlock $record */
        $record = $this->getRecord();

        $body = match (true) {
            in_array($record->key, BudgetTierLabels::keys(), true) => (string) ($data['title_value'] ?? ''),
            default => $this->bodyFor($record->key, $record->body, $data),
        };

        return ['body' => $body, 'updated_by' => auth()->id()];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function bodyFor(string $key, string $current, array $data): string
    {
        return match ($key) {
            ContentBlock::HERO_TITLE => (string) ($data['title_value'] ?? ''),
            ContentBlock::HERO_TEXT => (string) ($data['markdown_value'] ?? ''),
            ContentBlock::HOW_IT_WORKS => json_encode(array_values($data['steps'] ?? []), JSON_THROW_ON_ERROR),
            ContentBlock::FAQ => json_encode(array_values($data['questions'] ?? []), JSON_THROW_ON_ERROR),
            default => $current,
        };
    }

    protected function afterSave(): void
    {
        $this->auditUpdated('content_block.updated');
    }

    /**
     * Malformed JSON opens as an empty list instead of an error.
     *
     * @return list<array<string, mixed>>
     */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }
}
