<?php

namespace App\Filament\Resources\YearGroups\Schemas;

use App\Enums\LevelTier;
use App\Models\Curriculum;
use App\Models\YearGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class YearGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Fixed once created: moving a year group to another curriculum would
                // strand every learner and subject row that points at it.
                Select::make('curriculum_id')
                    ->relationship('curriculum', 'name')
                    ->required()
                    ->live()
                    ->disabledOn('edit')
                    ->dehydrated(),
                TextInput::make('code')
                    ->required()
                    ->maxLength(50)
                    ->rules(fn (Get $get, ?YearGroup $record): array => [
                        Rule::unique('year_groups', 'code')->where('curriculum_id', $get('curriculum_id'))->ignore($record?->id),
                    ])
                    ->validationMessages(['unique' => 'This curriculum already has a year group with that code.']),
                TextInput::make('label')
                    ->required()
                    ->maxLength(100)
                    ->rules(fn (Get $get, ?YearGroup $record): array => [
                        Rule::unique('year_groups', 'label')->where('curriculum_id', $get('curriculum_id'))->ignore($record?->id),
                    ])
                    ->validationMessages(['unique' => 'This curriculum already has a year group with that label.']),
                TextInput::make('sort')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(32767)
                    ->required()
                    ->helperText('Orders the year groups inside the curriculum; a tutor teaching "from A to B" teaches everything sorted between them.'),
                // Only the tiers the curriculum actually has (CurriculumCode::tiers()).
                // The curriculum on edit is the STORED one (the field is disabled and
                // the page drops any client value), so the options and the rule below
                // never follow a crafted `curriculum_id`.
                Select::make('level_tier')
                    ->required()
                    ->options(fn (Get $get, ?YearGroup $record): array => self::tierOptions(self::curriculumFor($get, $record)))
                    ->rules(fn (Get $get, ?YearGroup $record): array => [
                        Rule::in(array_keys(self::tierOptions(self::curriculumFor($get, $record)))),
                    ])
                    ->validationMessages(['in' => 'This curriculum does not have that level tier.']),
            ]);
    }

    private static function curriculumFor(Get $get, ?YearGroup $record): ?Curriculum
    {
        $id = $record instanceof YearGroup ? $record->curriculum_id : (int) $get('curriculum_id');

        return $id > 0 ? Curriculum::query()->find($id) : null;
    }

    /**
     * @return array<string, string>
     */
    private static function tierOptions(?Curriculum $curriculum): array
    {
        $tiers = $curriculum === null ? LevelTier::cases() : $curriculum->code->tiers();

        return collect($tiers)->mapWithKeys(fn (LevelTier $tier): array => [$tier->value => $tier->name])->all();
    }
}
