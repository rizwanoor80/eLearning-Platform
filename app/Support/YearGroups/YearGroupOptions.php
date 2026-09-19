<?php

namespace App\Support\YearGroups;

use App\Models\YearGroup;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * What the forms need from the controlled list: the options for a dependent
 * select, and the validation rule that pins a chosen year group to the
 * curriculum it is being used with (so a year group of another curriculum, or
 * any year group with no curriculum, is refused).
 */
class YearGroupOptions
{
    /**
     * @return list<array{id: int, curriculum_id: int, label: string}>
     */
    public static function all(): array
    {
        $options = [];

        foreach (YearGroup::query()->orderBy('curriculum_id')->orderBy('sort')->get(['id', 'curriculum_id', 'label']) as $group) {
            $options[] = ['id' => $group->id, 'curriculum_id' => $group->curriculum_id, 'label' => $group->label];
        }

        return $options;
    }

    public static function belongingTo(mixed $curriculumId): Exists
    {
        return Rule::exists('year_groups', 'id')->where('curriculum_id', is_numeric($curriculumId) ? (int) $curriculumId : 0);
    }
}
