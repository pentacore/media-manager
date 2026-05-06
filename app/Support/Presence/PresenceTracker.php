<?php

declare(strict_types=1);

namespace App\Support\Presence;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;

/**
 * Browser tabs ping `POST /heartbeat` while the user has interacted with
 * the page in the last couple of minutes; that endpoint funnels into
 * `markActive()`, which records the user's expected expiry timestamp in a
 * single Redis sorted set keyed by user id. `hasActiveUsers()` discards
 * stale members on the fly and reports whether anyone remains, so the
 * cache warmer can skip upstream calls when the app is idle.
 *
 * A sorted set (rather than per-user keys with SCAN) keeps the read path
 * driver-agnostic — phpredis and predis both expose ZADD/ZREMRANGEBYSCORE/
 * ZCARD with the same shape — and concentrates state in one Redis key,
 * which is easy to clean up between tests.
 */
class PresenceTracker
{
    /**
     * Records the user as active. Returns true when the presence set was
     * empty before this call — callers can use that signal to trigger an
     * immediate cache warm so the first arrival doesn't have to wait for
     * the next scheduler tick.
     */
    public function markActive(string $userId): bool
    {
        $wasEmpty = ! $this->hasActiveUsers();
        $expiresAt = $this->now() + $this->ttl();

        $this->connection()->zadd($this->key(), $expiresAt, $userId);

        return $wasEmpty;
    }

    public function hasActiveUsers(): bool
    {
        $this->dropExpired();

        return $this->connection()->zcard($this->key()) > 0;
    }

    public function activeCount(): int
    {
        $this->dropExpired();

        return (int) $this->connection()->zcard($this->key());
    }

    private function dropExpired(): void
    {
        $this->connection()->zremrangebyscore($this->key(), '-inf', (string) $this->now());
    }

    private function connection(): Connection
    {
        return Redis::connection();
    }

    private function key(): string
    {
        return (string) config('mediamanager.presence.key', 'presence:users');
    }

    private function ttl(): int
    {
        return (int) config('mediamanager.presence.heartbeat_ttl', 90);
    }

    private function now(): int
    {
        return Date::now()->getTimestamp();
    }
}
