<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\FindOrCreateSsoUser;
use App\Exceptions\SsoEmailCollisionException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AuthentikController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('authentik')->redirect();
    }

    public function callback(FindOrCreateSsoUser $findOrCreateSsoUser): RedirectResponse
    {
        try {
            $socialiteUser = Socialite::driver('authentik')->user();
        } catch (Throwable) {
            return to_route('login')->with('status', __('Authentication failed. Please try again.'));
        }

        try {
            $user = $findOrCreateSsoUser->execute(
                provider: 'authentik',
                ssoId: (string) $socialiteUser->getId(),
                email: $socialiteUser->getEmail(),
                name: $socialiteUser->getName(),
                avatarUrl: $socialiteUser->getAvatar(),
                emailVerified: (bool) ($socialiteUser->user['email_verified'] ?? false),
            );
        } catch (SsoEmailCollisionException) {
            return to_route('login')->with(
                'status',
                __('An account with this email already exists. Sign in with your existing credentials, or verify the email address in Authentik and try again.'),
            );
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }
}
