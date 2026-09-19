<?php

namespace App\Console\Commands;

use App\Support\YearGroups\LegacyYearGroupMapper;
use Illuminate\Console\Command;

class YearGroupsReport extends Command
{
    protected $signature = 'year-groups:report {--remap : Run the legacy mapping again first (idempotent) — use after adding or relabelling a year group}';

    protected $description = 'List learner and tutor-subject rows whose old free-text year group could not be mapped onto the controlled list';

    /**
     * Read-only unless `--remap` is given. The output carries the table, the row
     * id, the curriculum code and the original text only — never a learner's or
     * a tutor's name — so it is safe to paste into a log or a report.
     */
    public function handle(LegacyYearGroupMapper $mapper): int
    {
        if ($this->option('remap')) {
            $result = $mapper->run();
            $this->line(sprintf(
                'Remapped: learners %d matched, %d unmatched; tutor subjects %d matched, %d unmatched, %d tiers re-derived.',
                $result['learners']['matched'], $result['learners']['unmatched'],
                $result['tutor_subjects']['matched'], $result['tutor_subjects']['unmatched'], $result['tutor_subjects']['tiers_changed'],
            ));
        }

        $rows = $mapper->unmatched();

        if ($rows === []) {
            $this->info('No unmatched year groups.');

            return self::SUCCESS;
        }

        $this->table(['table', 'id', 'curriculum', 'field', 'text'], array_map(fn (array $row): array => array_values($row), $rows));
        $this->warn(count($rows).' unmatched value(s). The owner of each row picks a year group on their next edit; approved tutors stay permissive in search until re-vetting.');

        return self::SUCCESS;
    }
}
