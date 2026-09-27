<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * R135: the one small JSON endpoint the badges poll every 60 seconds while a tab is visible. It
 * answers `{messages, notifications}`; messaging counts 0 while `features.messaging` is off, and
 * `notifications` is 0 until the notification centre (8e) fills it. No broadcasting is involved.
 */
class UnreadCountsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $messages = EnsureFeatureEnabled::enabled('messaging')
            ? Message::query()
                ->whereNull('read_at')
                ->where('sender_user_id', '!=', $user->id)
                ->whereHas('conversation', fn ($conversation) => $conversation->forUser($user))
                ->count()
            : 0;

        return response()->json(['messages' => $messages, 'notifications' => 0]);
    }
}
