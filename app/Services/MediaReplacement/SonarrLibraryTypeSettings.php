<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\MediaReplacementScope;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Settings\MediaReplacementSettings;

class SonarrLibraryTypeSettings
{
    public function __construct(
        private readonly MediaReplacementSettings $mediaReplacementSettings,
    ) {}

    /**
     * @return list<array{
     *     root_folder_id: int,
     *     path: string,
     *     scope: 'anime'|'tv'|null
     * }>
     */
    public function forConnection(ServiceConnection $serviceConnection): array
    {
        if ($serviceConnection->type !== ServiceType::Sonarr) {
            return [];
        }

        $settings = is_array($serviceConnection->settings) ? $serviceConnection->settings : [];

        if (array_key_exists('sonarr_root_folders', $settings)) {
            return $this->normalize($settings['sonarr_root_folders']);
        }

        $legacyRootFolders = array_map(
            static fn (array $rootFolder): array => [
                'root_folder_id' => $rootFolder['root_folder_id'],
                'path' => $rootFolder['path'],
                'scope' => $rootFolder['scope'],
            ],
            array_filter(
                $this->mediaReplacementSettings->sonarrRootFolders(),
                static fn (array $rootFolder): bool => $rootFolder['service_connection_id'] === $serviceConnection->id,
            ),
        );

        return $this->normalize($legacyRootFolders);
    }

    /**
     * @param  array<array-key, mixed>  $settings
     * @param  array<array-key, mixed>  $rootFolders
     * @return array<array-key, mixed>
     */
    public function mergeInto(array $settings, array $rootFolders): array
    {
        $settings['sonarr_root_folders'] = $this->normalize($rootFolders);

        return $settings;
    }

    /**
     * @return list<array{
     *     root_folder_id: int,
     *     path: string,
     *     scope: 'anime'|'tv'|null
     * }>
     */
    private function normalize(mixed $rootFolders): array
    {
        if (! is_array($rootFolders)) {
            return [];
        }

        $normalizedRootFolders = [];

        foreach ($rootFolders as $rootFolder) {
            if (! is_array($rootFolder)) {
                continue;
            }

            $rootFolderIdValue = $rootFolder['root_folder_id'] ?? null;
            $rootFolderId = is_int($rootFolderIdValue)
                ? $rootFolderIdValue
                : (is_string($rootFolderIdValue) && ctype_digit($rootFolderIdValue)
                    ? (int) $rootFolderIdValue
                    : null);
            $path = $this->normalizePath($rootFolder['path'] ?? null);
            $scope = $rootFolder['scope'] ?? null;
            if ($rootFolderId === null) {
                continue;
            }

            if ($rootFolderId <= 0) {
                continue;
            }

            if ($path === null) {
                continue;
            }

            if (! is_null($scope) && ! in_array($scope, [MediaReplacementScope::Anime->value, MediaReplacementScope::Tv->value], true)) {
                continue;
            }

            $normalizedRootFolders[$rootFolderId] = [
                'root_folder_id' => $rootFolderId,
                'path' => $path,
                'scope' => $scope,
            ];
        }

        return array_values($normalizedRootFolders);
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
