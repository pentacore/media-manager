<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

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
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
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
    public function destroy(ProfileDeleteRequest $profileDeleteRequest): RedirectResponse
    {
        $user = $profileDeleteRequest->user();

        Auth::logout();

        $user->delete();

        $profileDeleteRequest->session()->invalidate();
        $profileDeleteRequest->session()->regenerateToken();

        return redirect('/');
    }
}
