<?php

declare(strict_types=1);

namespace App\Services\Emby;

use App\Models\ServiceConnection;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * Every user on an Emby server, trimmed to what the User Links page shows
 * and cached for a minute per connection. A failed fetch throws and is not
 * cached, so an outage renders as an error and recovers on the next load.
 */
final readonly class EmbyUserDirectory
{
    public const int TTL_SECONDS = 60;

    /**
     * @return list<array{id: string, name: string, is_admin: bool, last_activity_at: string|null}>
     *
     * @throws RequestException|ConnectionException
     */
    public function users(ServiceConnection $serviceConnection): array
    {
        /** @var list<array{id: string, name: string, is_admin: bool, last_activity_at: string|null}> */
        return Cache::remember(
            sprintf('emby-user-directory:%d', $serviceConnection->id),
            self::TTL_SECONDS,
            fn (): array => $this->fetch($serviceConnection),
        );
    }

    /**
     * @return array{id: string, name: string, is_admin: bool, last_activity_at: string|null}|null
     *
     * @throws RequestException|ConnectionException
     */
    public function find(ServiceConnection $serviceConnection, string $embyUserId): ?array
    {
        foreach ($this->users($serviceConnection) as $embyUser) {
            if ($embyUser['id'] === $embyUserId) {
                return $embyUser;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, name: string, is_admin: bool, last_activity_at: string|null}>
     *
     * @throws RequestException|ConnectionException
     */
    private function fetch(ServiceConnection $serviceConnection): array
    {
        $users = [];

        foreach (new EmbyClient($serviceConnection)->getUsers() as $embyUser) {
            if (! is_array($embyUser)) {
                continue;
            }

            $id = $embyUser['Id'] ?? null;
            $name = $embyUser['Name'] ?? null;

            if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
                continue;
            }

            $policy = is_array($embyUser['Policy'] ?? null) ? $embyUser['Policy'] : [];

            $users[] = [
                'id' => $id,
                'name' => $name,
                'is_admin' => (bool) ($policy['IsAdministrator'] ?? false),
                'last_activity_at' => $this->lastActivityAt($embyUser['LastActivityDate'] ?? null),
            ];
        }

        usort($users, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $users;
    }

    /**
     * One unparseable date shows as "never" rather than failing the list.
     */
    private function lastActivityAt(mixed $lastActivity): ?string
    {
        if (! is_string($lastActivity) || $lastActivity === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($lastActivity)->utc()->toIso8601String();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
