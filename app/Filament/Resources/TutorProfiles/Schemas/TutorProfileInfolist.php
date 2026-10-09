<?php

namespace App\Filament\Resources\TutorProfiles\Schemas;

use App\Enums\TutorReviewSection;
use App\Models\AbuseReport;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use App\Services\Scheduling\BookingLeadTime;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * Read-only review information. Bank fields are deliberately not shown here
 * — the admin payout view (full IBAN) is CP5 scope; this resource only
 * needs enough to decide approve/reject/request-changes/suspend.
 *
 * R151 (CP8 admin lesson operations) extends this with the tutor detail view: strikes,
 * late-report flags, open reports and disputes against this tutor, and their suspension
 * history — every section reads existing rows (`TutorProfile`'s new query methods); nothing
 * here writes anything, matching the resource's own `can*()` guards (view/list only).
 */
class TutorProfileInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('user.name')
                    ->label('Tutor'),
                TextEntry::make('user.email')
                    ->label('Email'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('headline'),
                TextEntry::make('bio')
                    ->columnSpanFull(),
                TextEntry::make('permit_number'),
                TextEntry::make('permit_expires_at')
                    ->date(),
                TextEntry::make('hourly_rate')
                    ->label('Hourly rate')
                    ->formatStateUsing(fn (?Money $state) => $state?->format()),
                TextEntry::make('lead_time')
                    ->label('Booking lead time')
                    ->getStateUsing(fn (TutorProfile $record): string => app(BookingLeadTime::class)->label(app(BookingLeadTime::class)->for($record))),
                TextEntry::make('agreement_version')
                    ->label('Agreement version accepted'),
                TextEntry::make('review_sections')
                    ->label('Sections to fix')
                    ->columnSpanFull()
                    ->getStateUsing(fn (TutorProfile $record): string => collect($record->review_sections ?? [])
                        ->map(fn (string $key): ?string => TutorReviewSection::tryFrom($key)?->label())
                        ->filter()
                        ->implode(', '))
                    ->placeholder('—'),
                TextEntry::make('review_note')
                    ->label('Review note')
                    ->columnSpanFull()
                    ->placeholder('—'),
                TextEntry::make('approved_at')
                    ->dateTime()
                    ->placeholder('—'),

                RepeatableEntry::make('strikes')
                    ->label('Strikes')
                    ->getStateUsing(fn (TutorProfile $record): array => $record->strikes()->with('lesson:id,starts_at')->latest('created_at')->get()
                        ->map(fn (TutorStrike $strike): array => [
                            'type' => str_replace('_', ' ', ucfirst($strike->type->value)),
                            'lesson_id' => $strike->lesson_id !== null ? "#{$strike->lesson_id}" : '—',
                            'created_at' => $strike->created_at?->format('Y-m-d H:i').' UTC',
                            'note' => $strike->note ?? '—',
                        ])->all())
                    ->schema([
                        TextEntry::make('type'),
                        TextEntry::make('lesson_id')->label('Lesson'),
                        TextEntry::make('created_at')->label('When'),
                        TextEntry::make('note')->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull()
                    ->placeholder('No strikes.'),

                RepeatableEntry::make('lateReportFlags')
                    ->label(fn (TutorProfile $record): string => 'Late-report flags (last 90 days: '.$record->lateReportFlags()->count().')')
                    ->getStateUsing(fn (TutorProfile $record): array => $record->lateReportFlags()
                        ->map(fn (Lesson $lesson): array => [
                            'lesson_id' => "#{$lesson->id}",
                            'report_late_at' => $lesson->report_late_at?->format('Y-m-d H:i').' UTC',
                        ])->all())
                    ->schema([
                        TextEntry::make('lesson_id')->label('Lesson'),
                        TextEntry::make('report_late_at')->label('Flagged late at'),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->placeholder('No late-report flags in the last 90 days.'),

                RepeatableEntry::make('openAbuseReports')
                    ->label('Open reports against this tutor')
                    ->getStateUsing(fn (TutorProfile $record): array => $record->openAbuseReports()
                        ->map(fn (AbuseReport $report): array => [
                            'reason' => str_replace('_', ' ', ucfirst($report->reason->value)),
                            'subject' => str_replace('_', ' ', ucfirst($report->subject_type->value)).' #'.$report->subject_id,
                            'description' => $report->description,
                            'created_at' => $report->created_at?->format('Y-m-d H:i').' UTC',
                        ])->all())
                    ->schema([
                        TextEntry::make('reason'),
                        TextEntry::make('subject')->label('Against'),
                        TextEntry::make('created_at')->label('Filed'),
                        TextEntry::make('description')->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull()
                    ->placeholder('No open reports.'),

                RepeatableEntry::make('openDisputes')
                    ->label('Open disputes')
                    ->getStateUsing(fn (TutorProfile $record): array => $record->openDisputes()
                        ->map(fn (Dispute $dispute): array => [
                            'lesson_id' => "#{$dispute->lesson_id}",
                            'reason' => str_replace('_', ' ', ucfirst($dispute->reason->value)),
                            'description' => $dispute->description,
                            'created_at' => $dispute->created_at?->format('Y-m-d H:i').' UTC',
                        ])->all())
                    ->schema([
                        TextEntry::make('lesson_id')->label('Lesson'),
                        TextEntry::make('reason'),
                        TextEntry::make('created_at')->label('Opened'),
                        TextEntry::make('description')->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull()
                    ->placeholder('No open disputes.'),

                RepeatableEntry::make('suspensionHistory')
                    ->label('Suspension history')
                    ->getStateUsing(fn (TutorProfile $record): array => $record->suspensionHistory()
                        ->map(fn (AuditLog $log): array => [
                            'action' => str_replace(['tutor.', '_'], ['', ' '], $log->action),
                            // `actor_user_id` is nullable (system-originated rows, e.g. the scheduled
                            // permit-expiry job — see `RecordAuditLog`'s own docblock), so `actor` is a
                            // genuine `User|null` here even though it's every row's actor in *this*
                            // list today (suspend/reinstate/sweep are always admin-initiated).
                            'actor' => $log->actor === null ? 'System' : $log->actor->name,
                            'created_at' => $log->created_at?->format('Y-m-d H:i').' UTC',
                            // `suspended`/`reinstated` carry a `review_note` (possibly null); the
                            // `suspension_sweep` row instead carries lesson/slot counts — no free-text
                            // note at all — so it is summarised from its own known keys, never guessed.
                            'note' => match ($log->action) {
                                'tutor.suspension_sweep' => sprintf(
                                    '%d reserved, %d confirmed lesson(s) cancelled; %d slot(s) paused.',
                                    $log->after['reserved_cancelled'] ?? 0,
                                    $log->after['confirmed_cancelled'] ?? 0,
                                    $log->after['slots_paused'] ?? 0,
                                ),
                                default => $log->after['review_note'] ?? '—',
                            },
                        ])->all())
                    ->schema([
                        TextEntry::make('action'),
                        TextEntry::make('actor'),
                        TextEntry::make('created_at')->label('When'),
                        TextEntry::make('note')->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull()
                    ->placeholder('No suspension history.'),
            ]);
    }
}
