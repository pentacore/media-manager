<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id): bool => $user->id === $id);

// Service-internals channels — broadcast detail an admin would see (health
// reasons, version info). Member+ only.
Broadcast::channel('services', fn (User $user): bool => $user->role->isAtLeast(UserRole::Member));

// Action requests fan-out — only members/admins can act on them, and the
// payload includes service routing detail not relevant to viewers.
Broadcast::channel('members.actions', fn (User $user): bool => $user->role->isAtLeast(UserRole::Member));

// SABnzbd download lifecycle. Member+ only since the queue page is gated the same way.
Broadcast::channel('members.sabnzbd', fn (User $user): bool => $user->role->isAtLeast(UserRole::Member));

// Open to anyone authenticated. The matching pages (dashboard, activity log,
// now playing, watch history) are also unrestricted, so locking the realtime
// feeds tighter than the pages just yields silent broken UI for viewers.
Broadcast::channel('emby.activity', fn (User $user): bool => true);
Broadcast::channel('dashboard', fn (User $user): bool => true);
Broadcast::channel('activity', fn (User $user): bool => true);

// AI price refresh job lifecycle. Admin-only — the AI Prices page is gated
// the same way and the payload exposes refresh internals (summary text,
// failure messages) that aren't relevant outside admin tooling.
Broadcast::channel('admin.ai-prices', fn (User $user): bool => $user->role === UserRole::Admin);
