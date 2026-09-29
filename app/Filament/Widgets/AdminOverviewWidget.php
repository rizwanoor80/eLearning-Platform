<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Reporting\AdminDashboardMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * CP8 9d (R152): the admin dashboard's stat cards. Each `Stat` below is backed by exactly one
 * `AdminDashboardMetrics` query ("one query per widget" — a card needing two numbers is either
 * two cards or a single `SUM(CASE...)` inside the metrics service, never two round trips from
 * here). Gated the same way every other admin-only Filament page in this app is gated
 * (`RequiresActiveAdmin`'s own check, repeated here rather than reused because `Widget::canView()`
 * takes no `Model $record` argument and the trait's signature does): a disabled admin must not
 * see revenue figures on `/admin` any more than they can reach a resource.
 */
class AdminOverviewWidget extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->role === Role::Admin && $user->status === UserStatus::Active;
    }

    protected function getStats(): array
    {
        $metrics = app(AdminDashboardMetrics::class);

        return [
            Stat::make('Lessons this week', (string) $metrics->lessonsThisWeek()),
            Stat::make('Revenue this week', $metrics->revenueThisWeek()->format()),
            Stat::make('Revenue this month', $metrics->revenueThisMonth()->format()),
            Stat::make('Reports overdue', (string) $metrics->reportsOverdue()),
            Stat::make('Permits expiring in 30 days', (string) $metrics->permitsExpiringSoon()),
            Stat::make('Failed charges (7 days)', (string) $metrics->failedChargesThisWeek()),
            Stat::make('Open safeguarding reports', (string) $metrics->openReports()),
            Stat::make('Open disputes', (string) $metrics->openDisputes()),
        ];
    }
}
