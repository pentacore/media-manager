<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\UserNotificationStored;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

final class DatabaseNotificationObserver
{
    public function created(DatabaseNotification $databaseNotification): void
    {
        if ($databaseNotification->notifiable_type !== new User()->getMorphClass()) {
            return;
        }

        event(new UserNotificationStored($databaseNotification->id, (int) $databaseNotification->notifiable_id));
    }
}
