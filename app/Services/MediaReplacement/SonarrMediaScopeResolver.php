<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\MediaReplacementScope;
use App\Models\ServiceConnection;
use Illuminate\Support\Str;

class SonarrMediaScopeResolver
{
    public function __construct(
        private readonly SonarrLibraryTypeSettings $sonarrLibraryTypeSettings,
    ) {}

    /**
     * @param  array<string, mixed>  $series
     */
    public function resolve(ServiceConnection $serviceConnection, array $series): ?MediaReplacementScope
    {
        $rootFolderPath = $this->normalizePath($series['rootFolderPath'] ?? null);
        $seriesPath = $this->normalizePath($series['path'] ?? null);

        if ($rootFolderPath === null && $seriesPath === null) {
            return ($series['seriesType'] ?? null) === 'anime'
                ? MediaReplacementScope::Anime
                : MediaReplacementScope::Tv;
        }

        $matches = array_values(array_filter(
            $this->sonarrLibraryTypeSettings->forConnection($serviceConnection),
            fn (array $rootFolder): bool => is_string($rootFolder['scope'])
                && $this->matchesPath($rootFolder['path'], $rootFolderPath, $seriesPath),
        ));

        usort(
            $matches,
            static fn (array $left, array $right): int => mb_strlen((string) $right['path']) <=> mb_strlen((string) $left['path']),
        );

        return MediaReplacementScope::tryFrom((string) ($matches[0]['scope'] ?? ''));
    }

    private function matchesPath(string $configuredPath, ?string $rootFolderPath, ?string $seriesPath): bool
    {
        if ($rootFolderPath !== null && $rootFolderPath === $configuredPath) {
            return true;
        }

        if ($seriesPath === null) {
            return false;
        }

        if ($seriesPath === $configuredPath) {
            return true;
        }

        if ($configuredPath === '/' && Str::startsWith($seriesPath, '/')) {
            return true;
        }

        return Str::startsWith($seriesPath, $configuredPath.'/');
    }

    private function normalizePath(mixed $path): ?string
    {
        if (! is_string($path) || ! mb_check_encoding($path, 'UTF-8')) {
            return null;
        }

        $normalizedPath = str_replace('\\', '/', trim($path));

        if ($normalizedPath !== '/') {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        return $normalizedPath === '' ? null : $normalizedPath;
    }
}
