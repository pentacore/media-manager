<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Services\Seerr\SeerrUserResolver;

class UserObserver
{
    public function __construct(private readonly SeerrUserResolver $seerrUserResolver) {}

    public function updated(User $user): void
    {
        if (! $user->wasChanged('email')) {
            return;
        }

        $this->seerrUserResolver->forgetMatchCache($user);
    }
}
