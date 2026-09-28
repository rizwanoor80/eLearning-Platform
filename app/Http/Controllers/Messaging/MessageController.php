<?php

namespace App\Http\Controllers\Messaging;

use App\Actions\Messaging\SendMessage;
use App\Enums\AbuseReportReason;
use App\Exceptions\ConversationClosedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\StoreMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Messaging\MessageMasker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Messages pages of both portals (R133): the same routes serve an account holder and a tutor, and
 * each sees only their own conversations. Everything here sits behind `feature:messaging`. Props are
 * built as explicit arrays: a model is never serialised, because a `User` carries an email address.
 */
class MessageController extends Controller
{
    private const THREAD_LIMIT = 200;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(Gate::allows('viewAny', Conversation::class), 404);

        $conversations = Conversation::query()
            ->forUser($user)
            ->with(['account:id,name', 'tutorProfile.user:id,name'])
            ->withCount(['messages as unread_count' => fn ($messages) => $messages->where('sender_user_id', '!=', $user->id)->whereNull('read_at')])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('messages/Index', [
            'conversations' => $conversations->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'counterpart' => $conversation->counterpartNameFor($user),
                'unread_count' => (int) $conversation->unread_count,
                'last_message_at' => $conversation->last_message_at?->setTimezone($user->timezone)->format('D, j M Y, g:i A'),
                'closed' => $conversation->isClosed(),
            ])->values()->all(),
        ]);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        $user = $request->user();
        abort_unless(Gate::allows('view', $conversation), 404);

        // Opening the thread reads the other side's messages. A query-builder update: only `read_at` changes.
        $conversation->messages()->where('sender_user_id', '!=', $user->id)->whereNull('read_at')->update(['read_at' => now()]);

        $messages = $conversation->messages()->latest('id')->limit(self::THREAD_LIMIT)->get()->reverse()->values();

        return Inertia::render('messages/Show', [
            'conversation' => [
                'id' => $conversation->id,
                'counterpart' => $conversation->counterpartNameFor($user),
                'closed' => $conversation->isClosed(),
                'closed_notice' => ConversationClosedException::NOTICE,
                'contact_hidden' => ! $conversation->contactIsVisible(),
                'placeholder' => MessageMasker::PLACEHOLDER,
                // CP7 8d (R137): `ConversationPolicy::reportAbuse` delegates to `view()` exactly,
                // already checked above to reach this page, so any viewer here may file one.
                'can_report_abuse' => true,
                'abuse_report_reasons' => AbuseReportReason::options(),
            ],
            'messages' => $messages->map(fn (Message $message): array => [
                'id' => $message->id,
                'mine' => $message->sender_user_id === $user->id,
                'body' => $message->body,
                'body_masked' => $message->body_masked,
                'sent_at' => $message->created_at?->setTimezone($user->timezone)->format('D, j M Y, g:i A'),
            ])->all(),
        ]);
    }

    public function store(StoreMessageRequest $request, Conversation $conversation, SendMessage $sendMessage): RedirectResponse
    {
        try {
            $sendMessage($request->user(), $conversation, $request->validated('body'));
        } catch (ConversationClosedException $e) {
            return back()->withErrors(['body' => $e->getMessage()]);
        }

        return redirect()->route('messages.show', $conversation);
    }
}
