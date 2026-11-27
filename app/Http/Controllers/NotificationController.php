<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Broadcasting\LiveChannels;
use App\Events\UserNotificationStored;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $notifications = fn (): array => $user
            ?->notifications()
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (DatabaseNotification $databaseNotification): array => [
                'id' => $databaseNotification->id,
                'type' => $databaseNotification->type,
                'data' => $databaseNotification->data,
                'read_at' => $databaseNotification->read_at?->toIso8601String(),
                'created_at' => $databaseNotification->created_at?->toIso8601String(),
            ])
            ->all() ?? [];
        $unreadCount = fn (): int => $user?->unreadNotifications()->count() ?? 0;

        // Without a user there is no channel to listen on: plain props.
        return Inertia::render('Notifications/Index', $user instanceof User ? [
            'notifications' => Inertia::live($notifications, on: UserNotificationStored::class, channel: LiveChannels::user($user->id)),
            'unreadCount' => Inertia::live($unreadCount, on: UserNotificationStored::class, channel: LiveChannels::user($user->id)),
        ] : [
            'notifications' => $notifications(),
            'unreadCount' => $unreadCount(),
        ]);
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()?->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->markAsRead();
        }

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()?->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $request->user()?->notifications()->where('id', $id)->delete();

        return back();
    }
}
