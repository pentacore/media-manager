<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

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

// Media replacement attempt lifecycle. Admin-only — the attempts pages are
// gated the same way and the payload names failure reasons and targets that
// are operator detail, not member-facing.
Broadcast::channel('admin.media-replacement', fn (User $user): bool => $user->role === UserRole::Admin);

// Per-conversation AI chat liveness — agent step updates ("Calling Sonarr…")
// for the duration of a single in-flight chat turn. Caller must own the
// conversation row. Auth allows subscription to archived conversations so
// in-flight turns that the user later archives still complete cleanly.
Broadcast::channel(
    'ai-chat.{userId}.{conversationId}',
    function (User $user, int $userId, string $conversationId): bool {
        if ((int) $user->id !== $userId) {
            return false;
        }

        return DB::table('agent_conversations')
            ->where('id', $conversationId)
            ->where('user_id', $user->id)
            ->exists();
    },
);
