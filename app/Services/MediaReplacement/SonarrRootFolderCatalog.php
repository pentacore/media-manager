<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrClient;
use Throwable;

class SonarrRootFolderCatalog
{
    /**
     * @return list<array{
     *     service_connection_id: int,
     *     connection_name: string,
     *     root_folder_id: int,
     *     path: string
     * }>
     */
    public function all(): array
    {
        $catalog = [];
        $connections = ServiceConnection::query()
            ->where('type', ServiceType::Sonarr)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($connections as $connection) {
            try {
                $rootFolders = new SonarrClient($connection)->getRootFolders();
            } catch (Throwable) {
                continue;
            }

            foreach ($rootFolders as $rootFolder) {
                if (! is_array($rootFolder)) {
                    continue;
                }

                if (! is_int($rootFolder['id'] ?? null)) {
                    continue;
                }

                if (! is_string($rootFolder['path'] ?? null)) {
                    continue;
                }

                if (trim($rootFolder['path']) === '') {
                    continue;
                }

                $catalog[] = [
                    'service_connection_id' => $connection->id,
                    'connection_name' => $connection->name,
                    'root_folder_id' => $rootFolder['id'],
                    'path' => $rootFolder['path'],
                ];
            }
        }

        return $catalog;
    }
}
