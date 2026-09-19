# Year-groups migration report (R33)

Migration: `database/migrations/2026_09_20_100000_create_year_groups_and_convert_columns.php`
Cycle 03 r2, step 2 (`cp/2.5b-year-groups`). Written 2026-09-20.

## What the migration does

1. Creates `year_groups` (`curriculum_id`, `code`, `label`, `sort`, `level_tier`) with unique `(curriculum_id, code)` and `(curriculum_id, label)`.
2. Inserts the 21 default year groups for every curriculum that already exists (`YearGroupDefaults::insertMissing()`); on a fresh install `YearGroupSeeder` does this after `CurriculumSeeder`.
3. Renames the free-text columns to `learners.year_group_legacy`, `tutor_subjects.level_min_legacy` and `tutor_subjects.level_max_legacy` (now nullable) and adds the foreign keys `learners.year_group_id`, `tutor_subjects.level_min_id`, `tutor_subjects.level_max_id`.
4. Runs `LegacyYearGroupMapper`.

`tutor_subjects.level_tier` stays a stored column. It is now derived (highest tier among the curriculum's year groups sorted between the row's lowest and highest year group) and re-derived whenever a year group's tier or sort is edited.

## Default year groups

| Curriculum | Year groups (label → tier) |
|---|---|
| GCSE / IGCSE | Year 7–9 → lower secondary; Year 10–11 → exam years 1 |
| A-Level | Year 12–13 → exam years 2 |
| IB MYP | MYP 1–3 → lower secondary; MYP 4–5 → exam years 1 |
| IB DP | DP 1–2 → exam years 2 |
| CBSE | Grade 6–8 → lower secondary; Grade 9–10 → exam years 1; Grade 11–12 → exam years 2 |

CBSE's "Grade N" labels are a default the admin can relabel in the Filament year-group CRUD.

## Mapping rule

A legacy text is mapped when, within the row's **own curriculum**, its label matches after trimming, collapsing internal whitespace and ignoring case (R33 says "exact label"; this is a deliberate, disclosed relaxation). The two ends of a tutor's range are mapped independently. A learner with no curriculum cannot be mapped. Mapped rows get their foreign key and their legacy text cleared; every other row keeps a null key and its original text. The mapper is idempotent and can be run again (`php artisan year-groups:report --remap`) after a label is added or corrected. A tutor-subject row whose two ends are both mapped has its tier re-derived from the year groups.

## Outcome

| Environment | Learners | Tutor-subject rows | Unmatched |
|---|---|---|---|
| Local development database (this run) | 0 | 0 | 0 |
| Seeded copy in `YearGroupMigrationTest` (rollback → legacy rows → migrate) | 6 | 2 | learners: `Reception`, and `Year 8` with no curriculum; tutor subject: `Sixth form` (upper end of a `Year 10` – `Sixth form` row) |

There is no shared, staging or production database, so no real data was migrated. The seeded-copy row is the test fixture, not real data: it shows exact and sloppy (`  yEAR   10 `) labels mapping, an unknown text and a curriculum-less learner staying unmapped, a half-matched range, a typed tier being replaced by the derived one, and a second run mapping nothing.

## Unmatched rows: what happens to them

There is no admin editor for learners or tutor subject rows, so "left null for admin correction" works like this in practice:

- `php artisan year-groups:report` lists every unmatched row: table, row id, curriculum code, field and original text. It never prints a learner's or a tutor's name, so its output is safe to paste.
- A parent sees the original text on the learner form and must choose a year group on the next edit (the minor's form requires one).
- A draft or `changes_requested` tutor with an unmapped subject row is sent back to the subjects step, where the original text is shown.
- An **approved** tutor with an unmapped row is locked out of onboarding; the row matches any year group of its curriculum in search (permissive) until cycle 03 step 3's re-vetting path (R36) lets an admin send them back.

## Rollback

`down()` restores the string columns from the year-group labels (or the legacy text) and drops the new columns and table. It exists so the mapping can be tested against legacy-shaped data; migrations are forward-only once merged.
