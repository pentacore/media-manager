<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use App\Models\ServiceConnection;
use App\Models\User;
use App\Support\Abilities;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Maps a MediaManager user to their Seerr user id. Seerr stores imported
 * Emby/Jellyfin users' media-server id in `jellyfinUserId` (32 hex, no
 * dashes); the user's EmbyUserLink ids are compared against it first, then
 * the email address. The result — including "no match" — is cached per user
 * for ten minutes so every Discover render does not page Seerr's user list.
 */
final readonly class SeerrUserResolver
{
    public const int CACHE_TTL_SECONDS = 600;

    private const int PAGE_SIZE = 100;

    /**
     * @throws RequestException|ConnectionException
     */
    public function resolve(ServiceConnection $serviceConnection, User $user): ?int
    {
        /** @var array{id: int|null} $match */
        $match = Cache::remember(
            $this->cacheKey($serviceConnection, $user),
            self::CACHE_TTL_SECONDS,
            fn (): array => ['id' => $this->match($serviceConnection, $user)],
        );

        return $match['id'];
    }

    /**
     * Every Seerr user, plus the current user's own match as the default.
     * Walks the Seerr user list once — building the picker list and finding
     * the match in the same pass — and primes the `resolve()` cache with the
     * result so a later `resolve()` call for this user+connection is free.
     *
     * `defaultId` is only ever the caller's own Emby/email match (or the
     * best match found before a partial failure) — it never falls back to
     * an arbitrary "first" Seerr user, since that would silently file a
     * request under a user the caller never picked. `partial` is true when
     * the walk was cut short by an upstream failure, so `users` is an
     * incomplete list a caller must not treat as proof a chosen id is
     * unknown to Seerr.
     *
     * @return array{users: list<array{id: int, label: string}>, defaultId: int|null, partial: bool}
     */
    public function pickerOptions(ServiceConnection $serviceConnection, User $user): array
    {
        $users = [];
        $defaultId = null;
        $partialMatch = null;
        $partial = false;

        try {
            $defaultId = $this->match($serviceConnection, $user, $users, $partialMatch);
            Cache::put($this->cacheKey($serviceConnection, $user), ['id' => $defaultId], self::CACHE_TTL_SECONDS);
        } catch (RequestException|ConnectionException) {
            // Keep whatever was collected before the failure, including the
            // best match found on a page fetched before a later page failed
            // — but never cache it, since the walk didn't complete and "no
            // match" is not a proven result.
            $defaultId = $partialMatch;
            $partial = true;
        }

        return ['users' => $users, 'defaultId' => $defaultId, 'partial' => $partial];
    }

    /**
     * @return array{canChooseUser: bool, userId: int|null, users: list<array{id: int, label: string}>, partial: bool, error: string|null}
     */
    public function requestingContext(ServiceConnection $serviceConnection, User $user): array
    {
        if ($user->can(Abilities::MANAGE_REQUESTS)) {
            $options = $this->pickerOptions($serviceConnection, $user);

            return [
                'canChooseUser' => true,
                'userId' => $options['defaultId'],
                'users' => $options['users'],
                'partial' => $options['partial'],
                'error' => $options['users'] === [] ? 'Seerr is unreachable right now.' : null,
            ];
        }

        try {
            return ['canChooseUser' => false, 'userId' => $this->resolve($serviceConnection, $user), 'users' => [], 'partial' => false, 'error' => null];
        } catch (RequestException|ConnectionException) {
            return ['canChooseUser' => false, 'userId' => null, 'users' => [], 'partial' => false, 'error' => 'Seerr is unreachable right now.'];
        }
    }

    /**
     * Emby-link match first, then email. When `$users` is passed by
     * reference it is filled with every Seerr user (for `pickerOptions()`)
     * and the walk never short-circuits, since the full list is needed;
     * otherwise (`resolve()`'s bare lookup) it stops at the first Emby match.
     * `$partialMatch`, when passed, always reflects the best match found so
     * far — including when the walk is cut short by an upstream failure on a
     * later page — so a caller that only needs "the best we've got" (the
     * picker default) doesn't lose a match already found on an earlier,
     * successfully fetched page.
     *
     * @param  list<array{id: int, label: string}>|null  $users
     *
     * @throws RequestException|ConnectionException
     */
    private function match(ServiceConnection $serviceConnection, User $user, ?array &$users = null, ?int &$partialMatch = null): ?int
    {
        $embyIds = $user->embyUserLinks()
            ->pluck('emby_user_id')
            ->map(fn (string $id): string => $this->normalizeId($id))
            ->filter()
            ->all();
        $email = Str::lower(trim((string) $user->email));
        $embyMatch = null;
        $emailMatch = null;

        try {
            foreach ($this->seerrUsers(new SeerrClient($serviceConnection)) as $seerrUser) {
                $id = (int) ($seerrUser['id'] ?? 0);

                if ($users !== null) {
                    $users[] = [
                        'id' => $id,
                        'label' => (string) ($seerrUser['displayName'] ?? $seerrUser['email'] ?? sprintf('User #%d', $id)),
                    ];
                }

                if ($embyMatch === null) {
                    $mediaServerId = $this->normalizeId((string) ($seerrUser['jellyfinUserId'] ?? ''));

                    if ($mediaServerId !== '' && in_array($mediaServerId, $embyIds, true)) {
                        $embyMatch = $id;

                        if ($users === null) {
                            return $embyMatch;
                        }
                    }
                }

                if ($emailMatch === null && $email !== '' && Str::lower(trim((string) ($seerrUser['email'] ?? ''))) === $email) {
                    $emailMatch = $id;
                }
            }
        } finally {
            $partialMatch = $embyMatch ?? $emailMatch;
        }

        return $embyMatch ?? $emailMatch;
    }

    private function cacheKey(ServiceConnection $serviceConnection, User $user): string
    {
        return sprintf('seerr:user-match:%d:%d', $serviceConnection->id, $user->id);
    }

    private function normalizeId(string $id): string
    {
        return Str::of($id)->trim()->lower()->replace('-', '')->toString();
    }

    /**
     * @return iterable<int, array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    private function seerrUsers(SeerrClient $seerrClient): iterable
    {
        $skip = 0;

        do {
            $payload = $seerrClient->getUsers(['take' => self::PAGE_SIZE, 'skip' => $skip]);
            $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];

            foreach ($results as $result) {
                if (is_array($result) && (int) ($result['id'] ?? 0) > 0) {
                    yield $result;
                }
            }

            $pages = (int) ($payload['pageInfo']['pages'] ?? 0);
            $page = (int) ($payload['pageInfo']['page'] ?? 0);
            $skip += self::PAGE_SIZE;
        } while (count($results) === self::PAGE_SIZE && ($pages === 0 || $page < $pages));
    }
}
