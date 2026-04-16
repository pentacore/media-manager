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
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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

        $response = Http::withHeaders([
            'X-Emby-Token' => $connection->api_key,
        ])->post($connection->url . '/Users/AuthenticateByName', [
            'Username' => $embyLoginRequest->input('username'),
            'Pw' => $embyLoginRequest->input('password'),
        ]);

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

        // Find existing user by email or create new one
        $user = User::where('email', $email)->first();

        if (! $user) {
            $role = User::count() === 0 ? UserRole::Admin : UserRole::Viewer;

            $user = User::create([
                'name' => $embyUsername,
                'email' => $email,
                'role' => $role,
                'email_verified_at' => now(),
            ]);
        }

        EmbyUserLink::create([
            'user_id' => $user->id,
            'emby_user_id' => $embyUserId,
            'emby_username' => $embyUsername,
        ]);

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }
}
