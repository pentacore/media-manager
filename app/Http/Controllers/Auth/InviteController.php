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

    public function accept(Request $request, User $user): RedirectResponse|Response
    {
        if (! $request->hasValidSignature()) {
            return to_route('login')->with('status', __('This invitation link has expired or is invalid.'));
        }

        Auth::login($user);

        if ($user->password) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/SetPassword');
    }

    public function setPassword(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => $this->passwordRules(),
        ]);

        $request->user()->update([
            'password' => $request->input('password'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password set successfully.')]);

        return to_route('dashboard');
    }
}
