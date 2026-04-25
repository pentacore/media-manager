<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EmbyLoginRequest;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class EmbyAuthController extends Controller
{
    public function store(EmbyLoginRequest $embyLoginRequest): RedirectResponse
    {
        $connection = ServiceConnection::where('type', ServiceType::Emby)
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            return to_route('login')->withErrors(['username' => __('Emby authentication is not available.')]);
        }

        try {
            $response = Http::baseUrl($connection->url)
                ->withHeaders([
                    'X-Emby-Token' => $connection->api_key,
                ])
                ->timeout(10)
                ->connectTimeout(3)
                ->post('/Users/AuthenticateByName', [
                    'Username' => $embyLoginRequest->input('username'),
                    'Pw' => $embyLoginRequest->input('password'),
                ]);
        } catch (ConnectionException) {
            return to_route('login')->withErrors(['username' => __('Emby authentication is temporarily unavailable.')]);
        }

        if (! $response->successful()) {
            return to_route('login')->withErrors(['username' => __('Invalid Emby credentials.')]);
        }

        $embyUserId = $response->json('User.Id');
        $embyUsername = $response->json('User.Name');

        // Find existing linked user
        $link = EmbyUserLink::where('emby_user_id', $embyUserId)->first();

        if ($link) {
            Auth::login($link->user, remember: true);

            return redirect()->intended(route('dashboard'));
        }

        // New Emby user — require email
        if (! $embyLoginRequest->filled('email')) {
            return back()->withErrors(['email' => __('Email is required for first-time Emby login.')]);
        }

        $email = $embyLoginRequest->input('email');

        // Reject when the email matches an existing local account that isn't Emby-linked.
        // Auto-linking by email match would allow an attacker with any Emby account to
        // hijack a local account by submitting the victim's email address.
        if (User::where('email', $email)->exists()) {
            return back()->withErrors([
                'email' => __('This email is already registered. Sign in with your original method first, then link your Emby account in Settings.'),
            ]);
        }

        $role = User::count() === 0 ? UserRole::Admin : UserRole::Viewer;

        // Create the user + link atomically and rely on the unique constraint
        // on emby_user_id as the final authority against races.
        try {
            $user = DB::transaction(function () use ($email, $embyUsername, $embyUserId, $role): User {
                $user = User::create([
                    'name' => $embyUsername,
                    'email' => $email,
                    'role' => $role,
                    'email_verified_at' => now(),
                ]);

                EmbyUserLink::create([
                    'user_id' => $user->id,
                    'emby_user_id' => $embyUserId,
                    'emby_username' => $embyUsername,
                ]);

                return $user;
            });
        } catch (QueryException) {
            return back()->withErrors([
                'username' => __('Unable to complete Emby sign-in. Please try again.'),
            ]);
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }
}
