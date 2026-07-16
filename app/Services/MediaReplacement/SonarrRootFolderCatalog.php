<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrClient;
use Throwable;

class SonarrRootFolderCatalog
{
    public function __construct(
        private readonly SonarrLibraryTypeSettings $sonarrLibraryTypeSettings,
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
        $configuredRootFolders = $this->sonarrLibraryTypeSettings->forConnection($serviceConnection);

        if (! $serviceConnection->is_active) {
            return $configuredRootFolders;
        }

        try {
            $importedRootFolders = new SonarrClient($serviceConnection)->getRootFolders();
        } catch (Throwable) {
            return $configuredRootFolders;
        }

        $scopeByRootFolderId = [];

        foreach ($configuredRootFolders as $configuredRootFolder) {
            $scopeByRootFolderId[$configuredRootFolder['root_folder_id']] = $configuredRootFolder['scope'];
        }

        $catalog = [];

        foreach ($importedRootFolders as $importedRootFolder) {
            if (! is_array($importedRootFolder)) {
                continue;
            }

            $rootFolderId = $importedRootFolder['id'] ?? null;
            $path = $importedRootFolder['path'] ?? null;
            if (! is_int($rootFolderId)) {
                continue;
            }

            if (! is_string($path)) {
                continue;
            }

            if (trim($path) === '') {
                continue;
            }

            $catalog[] = [
                'root_folder_id' => $rootFolderId,
                'path' => $path,
                'scope' => $scopeByRootFolderId[$rootFolderId] ?? null,
            ];
        }

        return $catalog === [] ? $configuredRootFolders : $catalog;
    }
}
