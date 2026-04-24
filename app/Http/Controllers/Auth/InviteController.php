<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InviteController extends Controller
{
    use PasswordValidationRules;

    private const string PENDING_INVITE_SESSION_KEY = 'pending_invite_user_id';

    public function accept(Request $request, User $user): RedirectResponse|Response
    {
        if (! $request->hasValidSignature()) {
            return to_route('login')->with('status', __('This invitation link has expired or is invalid.'));
        }

        // Single-use: once the invite has been accepted and a password set,
        // further clicks on the same signed URL are rejected even if still
        // inside the signature expiry window.
        if ($user->invite_accepted_at !== null) {
            return to_route('login')->with('status', __('This invitation link has already been used.'));
        }

        // Do NOT log the user in here. Clicking the emailed link alone must not
        // grant access. Instead, stash the target user's id in the session and
        // require them to set a password before we issue a session.
        $request->session()->put(self::PENDING_INVITE_SESSION_KEY, $user->id);

        return to_route('auth.set-password');
    }

    public function showSetPassword(Request $request): RedirectResponse|Response
    {
        if (! $this->resolveTargetUser($request) instanceof User) {
            return to_route('login')->with('status', __('Please accept your invitation first.'));
        }

        return Inertia::render('auth/SetPassword');
    }

    public function setPassword(Request $request): RedirectResponse
    {
        $user = $this->resolveTargetUser($request);

        if (! $user instanceof User) {
            return to_route('login')->with('status', __('Please accept your invitation first.'));
        }

        $request->validate([
            'password' => $this->passwordRules(),
        ]);

        $user->update([
            'password' => $request->input('password'),
            'invite_accepted_at' => now(),
        ]);

        $request->session()->forget(self::PENDING_INVITE_SESSION_KEY);

        if (! Auth::check() || Auth::id() !== $user->id) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password set successfully.')]);

        return to_route('dashboard');
    }

    /**
     * Resolve which user (if any) is allowed to use the set-password flow.
     *
     * There are two legitimate entry paths:
     *   1. The invite-accept flow populated the session key (user is NOT yet
     *      logged in). We fetch by id and require invite_accepted_at to be null.
     *   2. The user is already authenticated but has no password set (SSO or
     *      admin-created without set_password). Any such logged-in user may set
     *      their initial password here.
     */
    private function resolveTargetUser(Request $request): ?User
    {
        $pendingId = $request->session()->get(self::PENDING_INVITE_SESSION_KEY);

        if (is_int($pendingId)) {
            $user = User::find($pendingId);

            if ($user instanceof User && $user->invite_accepted_at === null) {
                return $user;
            }
        }

        $authenticated = $request->user();

        if ($authenticated instanceof User && $authenticated->password === null) {
            return $authenticated;
        }

        return null;
    }
}
