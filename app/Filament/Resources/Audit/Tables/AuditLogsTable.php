<?php

namespace App\Filament\Resources\Audit\Tables;

use App\Models\AuditLog;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

/**
 * CP8 9d (R152). Every column and filter reads straight off `audit_logs` — no write action, no
 * bulk action, `before`/`after` never shown inline (only inside the "View" modal, so a wide JSON
 * payload never breaks the table's layout, and a reader has to deliberately open a row before
 * seeing the full detail).
 *
 * `subject_type` is a plain FQCN (no morph map registered anywhere in this app), so it is always
 * rendered through `class_basename` rather than the raw class string.
 */
class AuditLogsTable
{
    private const SYSTEM_ACTOR = '__system__';

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('actor:id,name'))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('actor.name')->label('Actor')->placeholder('System')->default('System'),
                TextColumn::make('action')->label('Action')->badge()->searchable(),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (AuditLog $record): string => class_basename($record->subject_type).' #'.$record->subject_id),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Filter::make('actor_user_id')
                    ->schema([
                        Select::make('value')
                            ->label('Actor')
                            ->options(fn (): array => [self::SYSTEM_ACTOR => 'System']
                                + User::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                    ])
                    ->query(function ($query, array $data) {
                        $value = $data['value'] ?? null;

                        return match (true) {
                            $value === null || $value === '' => $query,
                            $value === self::SYSTEM_ACTOR => $query->whereNull('actor_user_id'),
                            default => $query->where('actor_user_id', $value),
                        };
                    }),
                Filter::make('action')
                    ->schema([
                        TextInput::make('value')->label('Action contains'),
                    ])
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->where('action', 'like', '%'.$data['value'].'%')
                        : $query),
                Filter::make('subject')
                    ->schema([
                        TextInput::make('type')->label('Subject type')->placeholder('e.g. TutorProfile, User, Lesson'),
                        TextInput::make('id')->label('Subject ID')->numeric(),
                    ])
                    ->query(function ($query, array $data) {
                        $type = $data['type'] ?? null;
                        $id = $data['id'] ?? null;

                        return $query
                            ->when(filled($type), fn ($q) => $q->where('subject_type', 'like', '%'.$type.'%'))
                            ->when(filled($id), fn ($q) => $q->where('subject_id', $id));
                    }),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))),
                // Deep-link target only (not a user-facing search box): the Safeguarding
                // resource links a report straight to its one suspension-sweep row via
                // `?tableFilters[id][value]=<id>`, sidestepping the subject_type ambiguity
                // between a tutor-only suspension (subject = TutorProfile) and an account
                // suspension (subject = User) by resolving the exact row server-side first.
                Filter::make('id')
                    ->schema([
                        TextInput::make('value')->numeric(),
                    ])
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->where('id', $data['value'])
                        : $query),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->color('gray')
                    ->modalHeading(fn (AuditLog $record): string => $record->action)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (AuditLog $record) => [
                        // Not `$record->actor?->name ?? 'System'`: Larastan types a `BelongsTo`
                        // as always-non-null regardless of the FK's own nullability, so it flags
                        // that `?->` as "unnecessary" -- but `actor_user_id` genuinely is null for
                        // a system-originated row, and blindly following that suggestion (using
                        // `->` instead) would throw on exactly those rows. Branching on the raw FK
                        // column instead keeps the relation access inside the branch where it is
                        // actually non-null, which satisfies both PHPStan and the runtime.
                        TextEntry::make('actor.name')->label('Actor')->default('System')->state($record->actor_user_id === null ? 'System' : $record->actor->name),
                        TextEntry::make('created_at')->label('When')->dateTime()->state($record->created_at),
                        TextEntry::make('subject')->label('Subject')->state(class_basename($record->subject_type).' #'.$record->subject_id),
                        ...self::confirmedLessonIdsEntry($record),
                        KeyValueEntry::make('before')->label('Before')->state($record->before ?? [])->visible(filled($record->before)),
                        KeyValueEntry::make('after')->label('After')->state($record->after ?? [])->visible(filled($record->after)),
                    ]),
            ]);
    }

    /**
     * `confirmed_lesson_ids` exists only on a `user.suspension_sweep` row's `after` payload
     * (`CancelSuspendedAccountLessons`), never on a `tutor.suspension_sweep` row. This is not an
     * oversight: the two sweeps make opposite decisions about a `confirmed` lesson, and each row
     * shape follows from what it actually did —
     * - `CancelSuspendedTutorLessons::cancelConfirmedLessons()` (tutor sweep) cancels and refunds
     *   every confirmed lesson itself (`CancelSuspendedTutorLessons.php:33-34`), so nothing is
     *   left for an admin to act on — the row's `confirmed_cancelled` is a plain count.
     * - `CancelSuspendedAccountLessons::__invoke()` (account sweep) leaves confirmed lessons
     *   untouched on purpose — "R138: an admin decision, not automated"
     *   (`CancelSuspendedAccountLessons.php:36`) — and lists their ids specifically so an admin
     *   knows which ones still need manual follow-up.
     *
     * So this label must say the lessons are still live, not cancelled — the previous wording
     * ("Confirmed lessons cancelled") claimed the opposite of what the sweep actually did.
     *
     * Rendered as plain text, not a link: `LessonResource` registers no `view` page
     * (`getUrl('view', ...)` would throw) and `LessonsTable` has no `->searchable()` column
     * either, so there is no working link target to point at without widening 9d's own scope into
     * `LessonResource`/`LessonsTable`, which R152 does not name.
     *
     * @return array<int, TextEntry>
     */
    private static function confirmedLessonIdsEntry(AuditLog $record): array
    {
        $ids = $record->after['confirmed_lesson_ids'] ?? [];

        if (! is_array($ids) || $ids === []) {
            return [];
        }

        return [
            TextEntry::make('confirmed_lesson_ids')
                ->label('Confirmed lessons still active (left untouched — needs admin follow-up)')
                ->state('#'.implode(', #', $ids)),
        ];
    }
}
