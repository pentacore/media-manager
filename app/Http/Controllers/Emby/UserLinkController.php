<?php

declare(strict_types=1);

namespace App\Http\Controllers\Emby;

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Emby\LinkDirectoryUserRequest;
use App\Http\Requests\Emby\StoreUserLinkRequest;
use App\Http\Resources\EmbyUserLinkResource;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Emby\EmbyUserDirectory;
use Illuminate\Database\Eloquent\Collection;
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
    public function index(Request $request, EmbyUserDirectory $embyUserDirectory): Response
    {
        $links = EmbyUserLink::with('user:id,name,email')->latest()->get();

        return Inertia::render('Emby/UserLinks', [
            'links' => EmbyUserLinkResource::collection($links)->toArray($request),
            'appUsers' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
                ->all(),
            'embyUsers' => Inertia::defer(fn (): array => $this->directory($embyUserDirectory, $links), 'emby-users'),
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

    /**
     * Admin link from the Emby users list: no Emby password needed, the
     * picked id must still be in the (cached) directory.
     */
    public function storeFromDirectory(LinkDirectoryUserRequest $linkDirectoryUserRequest, EmbyUserDirectory $embyUserDirectory): RedirectResponse
    {
        $validated = $linkDirectoryUserRequest->validated();
        $user = User::query()->findOrFail((int) $validated['user_id']);

        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Emby);
        } catch (ModelNotFoundException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No active Emby connection configured.')]);

            return back();
        }

        try {
            $embyUser = $embyUserDirectory->find($connection, (string) $validated['emby_user_id']);
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Emby is unreachable right now.')]);

            return back();
        }

        if ($embyUser === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That Emby user no longer exists — refresh the list.')]);

            return back();
        }

        if (EmbyUserLink::query()->where('emby_user_id', $embyUser['id'])->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That Emby account is already linked to another user.')]);

            return back();
        }

        try {
            EmbyUserLink::create([
                'user_id' => $user->id,
                'emby_user_id' => $embyUser['id'],
                'emby_username' => $embyUser['name'],
            ]);
        } catch (QueryException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That Emby account is already linked to another user.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Linked :emby to :name.', ['emby' => $embyUser['name'], 'name' => $user->name])]);

        return back();
    }

    /**
     * @param  Collection<int, EmbyUserLink>  $links
     * @return array{users: list<array<string, mixed>>, error: string|null}
     */
    private function directory(EmbyUserDirectory $embyUserDirectory, Collection $links): array
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Emby);
        } catch (ModelNotFoundException) {
            return ['users' => [], 'error' => __('No active Emby connection is configured.')];
        }

        try {
            $embyUsers = $embyUserDirectory->users($connection);
        } catch (RequestException|ConnectionException) {
            return ['users' => [], 'error' => __('Emby is unreachable right now — the user list could not be loaded.')];
        }

        $linksByEmbyId = $links->keyBy('emby_user_id');
        $users = [];

        foreach ($embyUsers as $embyUser) {
            $link = $linksByEmbyId->get($embyUser['id']);

            $users[] = [
                ...$embyUser,
                'link' => $link instanceof EmbyUserLink
                    ? ['id' => $link->id, 'user' => ['id' => $link->user->id, 'name' => $link->user->name]]
                    : null,
            ];
        }

        return ['users' => $users, 'error' => null];
    }

    public function destroy(EmbyUserLink $embyUserLink, AuditLogger $auditLogger): RedirectResponse
    {
        $user = request()->user();

        abort_unless(
            $embyUserLink->user_id === $user->id || $user->role === UserRole::Admin,
            403
        );

        $embyUserLink->loadMissing('user:id,name');
        $embyUserLink->delete();

        $auditLogger->record(
            'emby.user_unlinked',
            $embyUserLink,
            sprintf('Unlinked Emby user "%s" from %s.', $embyUserLink->emby_username, $embyUserLink->user->name),
            context: ['emby_user_id' => $embyUserLink->emby_user_id, 'user_id' => $embyUserLink->user_id],
        );

        if ($user->role === UserRole::Admin) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Link removed.')]);

            return to_route('emby.links.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Emby account unlinked.')]);

        return back();
    }
}
