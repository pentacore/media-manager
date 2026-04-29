<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

        $notifications = $user
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

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'unreadCount' => $user?->unreadNotifications()->count() ?? 0,
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
