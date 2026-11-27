<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PushSubscription;
use App\Models\User;

class PushSubscriptionPolicy
{
    public function delete(User $user, PushSubscription $pushSubscription): bool
    {
        return $pushSubscription->user_id === $user->id;
    }
}
