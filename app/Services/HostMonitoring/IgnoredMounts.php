<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

/**
 * The mounts the disk rules leave alone: `monitoring.disk.ignore_mounts`, a comma-separated list of shell globs
 * (`/mnt/user,/mnt/disk*`) matched against the whole mount path, where `*` does not cross a `/`. Their samples are
 * still stored and charted; only the disk usage and fill rate rules skip them, so their open incidents resolve.
 */
final readonly class IgnoredMounts
{
    /**
     * @template TFilesystem of array{mount: string}
     *
     * @param  list<TFilesystem>  $filesystems
     * @return list<TFilesystem>
     */
    public static function reject(array $filesystems): array
    {
        $patterns = self::patterns();

        if ($patterns === []) {
            return $filesystems;
        }

        return array_values(array_filter(
            $filesystems,
            static fn (array $filesystem): bool => ! self::matchesAny($filesystem['mount'], $patterns),
        ));
    }

    /**
     * @return list<string>
     */
    public static function patterns(): array
    {
        $configured = config('mediamanager.monitoring.disk.ignore_mounts');

        if (! is_string($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $configured)),
            static fn (string $pattern): bool => $pattern !== '',
        ));
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function matchesAny(string $mount, array $patterns): bool
    {
        return array_any($patterns, static fn (string $pattern): bool => fnmatch($pattern, $mount, FNM_PATHNAME));
    }
}
