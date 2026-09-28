<?php

namespace App\Http\Controllers;

use App\Support\Notifications\NotificationPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CP7 8e (R139): the notification centre — a list page, mark one read, mark all read. Every row is
 * resolved through `$request->user()->notifications()` (the Notifiable relation), never a bare
 * `DatabaseNotification::find()`, so requesting another user's notification id 404s the same way a
 * bare-model Conversation/Lesson lookup would.
 */
class NotificationController extends Controller
{
    private const LIST_LIMIT = 50;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest('created_at')
            ->limit(self::LIST_LIMIT)
            ->get();

        return Inertia::render('notifications/Index', [
            'notifications' => $notifications
                ->map(fn (DatabaseNotification $notification): array => NotificationPresenter::present($notification, $user))
                ->values()
                ->all(),
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $row = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $row->markAsRead();

        return back();
    }

    /**
     * Mark-read-and-go, as one request: a row's link is rendered as a POST to this action (Inertia
     * `Link` with `method="post"`) instead of a plain GET, so a click never races a separate mark-read
     * POST for the same row (two Inertia visits firing together, one of which Inertia cancels). The
     * redirect target is computed server-side from the row itself, never from client input, so there is
     * no open-redirect surface here.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $user = $request->user();
        $row = $user->notifications()->whereKey($notification)->firstOrFail();
        $row->markAsRead();

        $url = NotificationPresenter::present($row, $user)['url'];

        return redirect($url ?? route('notifications.index'));
    }

    /**
     * A query update, not a collection load: this can be many rows, and only `read_at` changes.
     */
    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
