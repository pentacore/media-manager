<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Models\NotificationDestination;
use App\Models\User;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * The single place admin-facing notifications fan out from. Sends to every
 * admin user (their PreferenceResolver rows decide the channels) and, in
 * the same Notification::send() call, to every enabled global destination,
 * so a global Discord/Telegram/webhook/ntfy mirror fires exactly once per
 * notification no matter how many admins exist.
 */
class AdminNotifier
{
    public function send(BaseNotification $notification): void
    {
        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, $notification);
    }

    /**
     * @return Collection<int, User>
     */
    public function admins(): Collection
    {
        return User::query()->where('role', UserRole::Admin)->get();
    }

    /**
     * @return Collection<int, User|NotificationDestination>
     */
    public function recipients(): Collection
    {
        /** @var Collection<int, User|NotificationDestination> $recipients */
        $recipients = $this->admins()->toBase();

        return $recipients->concat(NotificationDestination::query()->enabled()->get());
    }
}
