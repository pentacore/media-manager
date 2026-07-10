<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emby;

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Emby\StoreUserLinkRequest;
use App\Http\Resources\EmbyUserLinkResource;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class UserLinkController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Emby/UserLinks', [
            'links' => EmbyUserLinkResource::collection(
                EmbyUserLink::with('user:id,name,email')->latest()->get()
            )->toArray($request),
        ]);
    }

    public function store(StoreUserLinkRequest $storeUserLinkRequest): RedirectResponse
    {
        $user = $storeUserLinkRequest->user();

        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Emby);
        } catch (ModelNotFoundException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No active Emby connection configured.')]);

            return back();
        }

        try {
            $response = Http::baseUrl(rtrim($connection->url, '/'))
                ->withHeaders([
                    'X-Emby-Authorization' => 'MediaBrowser Client="MediaManager", Device="Web", DeviceId="mediamanager-link", Version="1.0.0"',
                ])
                ->timeout(10)
                ->connectTimeout(3)
                ->post('/Users/AuthenticateByName', [
                    'Username' => $storeUserLinkRequest->validated('emby_username'),
                    'Pw' => $storeUserLinkRequest->validated('password'),
                ]);
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to contact Emby.')]);

            return back();
        }

        if (! $response->successful()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Invalid Emby credentials.')]);

            return back();
        }

        $body = $response->json();
        $embyUserId = $body['User']['Id'] ?? null;
        $embyUsername = $body['User']['Name'] ?? null;

        if ($embyUserId === null || $embyUsername === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Unexpected response from Emby.')]);

            return back();
        }

        if (EmbyUserLink::where('emby_user_id', $embyUserId)->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That Emby account is already linked to another user.')]);

            return back();
        }

        // Rely on the database unique constraint on emby_user_id as a second
        // line of defence against races between the check-exists and insert.
        try {
            EmbyUserLink::create([
                'user_id' => $user->id,
                'emby_user_id' => $embyUserId,
                'emby_username' => $embyUsername,
            ]);
        } catch (QueryException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That Emby account is already linked to another user.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Emby account linked.')]);

        return back();
    }

    public function destroy(EmbyUserLink $embyUserLink): RedirectResponse
    {
        $user = request()->user();

        abort_unless(
            $embyUserLink->user_id === $user->id || $user->role === UserRole::Admin,
            403
        );

        $embyUserLink->delete();

        if ($user->role === UserRole::Admin) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Link removed.')]);

            return to_route('emby.links.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Emby account unlinked.')]);

        return back();
    }
}
