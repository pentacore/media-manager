<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\SsoEmailCollisionException;
use App\Models\User;

class FindOrCreateSsoUser
{
    /**
     * @param  bool  $emailVerified  whether the IdP asserted the email as
     *                               verified (OIDC `email_verified` claim)
     *
     * @throws SsoEmailCollisionException when the email matches an existing
     *                                    local account but the IdP did not assert it verified
     */
    public function execute(
        string $provider,
        string $ssoId,
        string $email,
        string $name,
        ?string $avatarUrl = null,
        bool $emailVerified = false,
    ): User {
        // Find returning SSO user by provider + sso_id
        $user = User::where('sso_provider', $provider)
            ->where('sso_id', $ssoId)
            ->first();

        if ($user) {
            return $user;
        }

        // Find existing user by email and link SSO. Only when the IdP asserts
        // the email verified: an IdP that allows self-registration with an
        // arbitrary address must not hand over a pre-existing local account
        // (same takeover EmbyAuthController refuses on its login path).
        $user = User::where('email', $email)->first();

        if ($user) {
            throw_unless($emailVerified, SsoEmailCollisionException::class, sprintf(
                'Refusing to auto-link SSO identity %s:%s to existing account %d — email not asserted verified by the IdP.',
                $provider,
                $ssoId,
                $user->id,
            ));

            $user->update([
                'sso_provider' => $provider,
                'sso_id' => $ssoId,
                'avatar_url' => $avatarUrl ?? $user->avatar_url,
            ]);

            // The IdP asserted the address verified, so local verification
            // is redundant.
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
        // explicitly — but only when the IdP actually asserted the address
        // verified; otherwise the normal verification flow applies.
        if ($emailVerified) {
            $user->markEmailAsVerified();
        }

        return $user;
    }
}
