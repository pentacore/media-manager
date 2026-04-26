<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id): bool => $user->id === $id);

Broadcast::channel('services', fn (User $user): bool => $user->role->isAtLeast(UserRole::Member));

Broadcast::channel('emby.activity', fn (User $user): bool => $user->role->isAtLeast(UserRole::Member));

Broadcast::channel('dashboard', fn (User $user): bool => $user->role->isAtLeast(UserRole::Member));

Broadcast::channel('activity', fn (User $user): bool => $user->role->isAtLeast(UserRole::Member));
