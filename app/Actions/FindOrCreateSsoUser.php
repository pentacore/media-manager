<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\User;

class FindOrCreateSsoUser
{
    public function execute(
        string $provider,
        string $ssoId,
        string $email,
        string $name,
        ?string $avatarUrl = null,
    ): User {
        // Find returning SSO user by provider + sso_id
        $user = User::where('sso_provider', $provider)
            ->where('sso_id', $ssoId)
            ->first();

        if ($user) {
            return $user;
        }

        // Find existing user by email and link SSO
        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'sso_provider' => $provider,
                'sso_id' => $ssoId,
                'avatar_url' => $avatarUrl ?? $user->avatar_url,
            ]);

            return $user;
        }

        // Create new user — admin if first user, viewer otherwise
        $role = User::count() === 0 ? UserRole::Admin : UserRole::Viewer;

        return User::create([
            'name' => $name,
            'email' => $email,
            'sso_provider' => $provider,
            'sso_id' => $ssoId,
            'avatar_url' => $avatarUrl,
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }
}
