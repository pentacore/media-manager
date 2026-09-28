<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\LastAdminException;
use App\Actions\ModifyUserAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'embyLinks' => $user
                ? $user->embyUserLinks()->latest()->get()->map(fn ($link): array => [
                    'id' => $link->id,
                    'emby_username' => $link->emby_username,
                    'created_at' => $link->created_at?->toIso8601String(),
                ])->all()
                : [],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $profileUpdateRequest): RedirectResponse
    {
        $profileUpdateRequest->user()->fill($profileUpdateRequest->validated());

        if ($profileUpdateRequest->user()->isDirty('email')) {
            $profileUpdateRequest->user()->email_verified_at = null;
        }

        $profileUpdateRequest->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $profileDeleteRequest, ModifyUserAccess $modifyUserAccess): RedirectResponse
    {
        $user = $profileDeleteRequest->user();

        try {
            // Log out inside the guarded delete: after the row is gone,
            // logout's remember-token save would re-insert the user.
            $modifyUserAccess->delete($user, beforeDelete: static fn () => Auth::logout());
        } catch (LastAdminException) {
            return back()->withErrors([
                'password' => __('You are the only admin. Make another user an admin before deleting your account.'),
            ]);
        }

        $profileDeleteRequest->session()->invalidate();
        $profileDeleteRequest->session()->regenerateToken();

        return redirect('/');
    }
}
