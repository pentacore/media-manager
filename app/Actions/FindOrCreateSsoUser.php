<?php

declare(strict_types=1);

namespace App\Actions;

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

            // The SSO provider has authenticated the user, so treat their email
            // as verified even if local verification never completed.
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            return $user;
        }

        // Create new user — admin if first user, viewer otherwise
        $user = resolve(CreateUserWithBootstrapRole::class)->execute([
            'name' => $name,
            'email' => $email,
            'sso_provider' => $provider,
            'sso_id' => $ssoId,
            'avatar_url' => $avatarUrl,
        ]);

        // email_verified_at is guarded against mass assignment, so set it
        // explicitly — SSO sign-in implies the provider verified the email.
        $user->markEmailAsVerified();

        return $user;
    }
}
