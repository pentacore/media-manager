<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

/**
 * Admin-only flows that mutate Emby ↔ App user links by username (no
 * password required) and bulk-import Emby users into the local user
 * table. Distinct from UserLinkController, which is the user-facing
 * "link my own account" path that needs the user's password.
 */
class EmbyLinkController extends Controller
{
    /**
     * Link an existing app user to an Emby account by username. Looks
     * up the matching Emby user via /Users (admin-credential token).
     */
    public function link(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'emby_username' => ['required', 'string', 'max:200'],
        ]);

        if ($user->embyUserLinks()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __(':name already has a linked Emby account.', ['name' => $user->name])]);

            return back();
        }

        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Emby);
        } catch (ModelNotFoundException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No active Emby connection configured.')]);

            return back();
        }

        $match = $this->findEmbyUserByUsername($connection, $validated['emby_username']);

        if ($match === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No Emby user named ":name".', ['name' => $validated['emby_username']])]);

            return back();
        }

        if (EmbyUserLink::where('emby_user_id', $match['id'])->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That Emby account is already linked to another user.')]);

            return back();
        }

        try {
            EmbyUserLink::create([
                'user_id' => $user->id,
                'emby_user_id' => $match['id'],
                'emby_username' => $match['username'],
            ]);
        } catch (QueryException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to link Emby account.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Linked :name to Emby account ":emby".', [
            'name' => $user->name,
            'emby' => $match['username'],
        ])]);

        return back();
    }

    /**
     * Bulk-import every Emby user as a local viewer account, creating
     * the EmbyUserLink at the same time. Skips users that already have
     * a matching link or a duplicate email. Idempotent — re-running it
     * only adds new arrivals.
     */
    public function import(Request $request): RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Emby);
        } catch (ModelNotFoundException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No active Emby connection configured.')]);

            return back();
        }

        try {
            $embyUsers = $this->fetchEmbyUsers($connection);
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Failed to contact Emby.')]);

            return back();
        }

        $linkedEmbyIds = EmbyUserLink::query()->pluck('emby_user_id')->all();
        $created = 0;
        $skipped = 0;

        foreach ($embyUsers as $embyUser) {
            $embyId = $embyUser['Id'] ?? null;
            $embyName = $embyUser['Name'] ?? null;

            if ($embyId === null || $embyName === null) {
                $skipped++;

                continue;
            }

            if (in_array($embyId, $linkedEmbyIds, true)) {
                $skipped++;

                continue;
            }

            // Synthesize a placeholder email so the user record satisfies
            // the unique-email constraint without colliding. Admin can
            // edit it after the import.
            $email = sprintf('emby+%s@local.invalid', strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $embyName)));

            $user = User::query()->where('email', $email)->first();

            if (! $user) {
                $user = User::create([
                    'name' => $embyName,
                    'email' => $email,
                    'role' => UserRole::Viewer,
                    'password' => bcrypt(bin2hex(random_bytes(16))),
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            try {
                EmbyUserLink::create([
                    'user_id' => $user->id,
                    'emby_user_id' => $embyId,
                    'emby_username' => $embyName,
                ]);
                $created++;
            } catch (QueryException) {
                $skipped++;
            }
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Imported :created Emby user(s), skipped :skipped.', [
                'created' => $created,
                'skipped' => $skipped,
            ]),
        ]);

        return back();
    }

    /**
     * @return array{id: string, username: string}|null
     */
    private function findEmbyUserByUsername(ServiceConnection $serviceConnection, string $username): ?array
    {
        try {
            $users = $this->fetchEmbyUsers($serviceConnection);
        } catch (RequestException|ConnectionException) {
            return null;
        }

        $needle = strtolower($username);

        foreach ($users as $user) {
            $name = $user['Name'] ?? null;
            $id = $user['Id'] ?? null;

            if (is_string($name) && is_string($id) && strtolower($name) === $needle) {
                return ['id' => $id, 'username' => $name];
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    private function fetchEmbyUsers(ServiceConnection $serviceConnection): array
    {
        $apiKey = $serviceConnection->api_key;

        return Http::baseUrl(rtrim($serviceConnection->url, '/'))
            ->withHeaders([
                'X-Emby-Token' => $apiKey ?? '',
            ])
            ->timeout(10)
            ->connectTimeout(3)
            ->get('/Users')
            ->throw()
            ->json() ?? [];
    }
}
