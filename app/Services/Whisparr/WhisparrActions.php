<?php

declare(strict_types=1);

namespace App\Services\Whisparr;

use App\Cache\Services\WhisparrCache;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use InvalidArgumentException;

class WhisparrActions implements ActionExecutor
{
    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        return match ($actionRequest->type) {
            'whisparr_delete_item' => $this->deleteItem($actionRequest),
            'whisparr_add_item' => $this->addItem($actionRequest),
            'whisparr_monitor_item' => $this->monitorItem($actionRequest),
            'whisparr_set_quality_profile' => $this->setQualityProfile($actionRequest),
            default => throw new InvalidArgumentException(sprintf('WhisparrActions cannot execute type "%s"', $actionRequest->type)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteItem(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $itemId = (int) ($payload['whisparr_item_id'] ?? 0);
        throw_if($itemId <= 0, InvalidArgumentException::class, 'whisparr_item_id is required');

        $deleteFiles = (bool) ($payload['delete_files'] ?? false);
        $serviceConnection = ServiceConnection::resolvePinned($payload, ServiceType::Whisparr);
        new WhisparrClient($serviceConnection)->deleteItem($itemId, $deleteFiles);
        new WhisparrCache($serviceConnection)->bustAll();

        return ['whisparr_item_id' => $itemId, 'delete_files' => $deleteFiles];
    }

    /**
     * @return array<string, mixed>
     */
    private function addItem(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $tmdbId = (int) ($payload['tmdb_id'] ?? 0);
        throw_if($tmdbId <= 0, InvalidArgumentException::class, 'tmdb_id is required');

        $serviceConnection = ServiceConnection::resolvePinned($payload, ServiceType::Whisparr);
        $whisparrClient = new WhisparrClient($serviceConnection);

        $candidates = $whisparrClient->searchItems(sprintf('tmdb:%d', $tmdbId));
        throw_if($candidates === [], InvalidArgumentException::class, sprintf('No item found in Whisparr lookup for tmdb_id %d', $tmdbId));

        $item = $whisparrClient->addItem(array_merge($candidates[0], [
            'qualityProfileId' => (int) ($payload['quality_profile_id'] ?? 0),
            'rootFolderPath' => (string) ($payload['root_folder_path'] ?? ''),
            'monitored' => (bool) ($payload['monitored'] ?? true),
            'addOptions' => [
                ($serviceConnection->whisparrVersion()->resource() === 'series' ? 'searchForMissingEpisodes' : 'searchForMovie') => true,
            ],
        ]));

        new WhisparrCache($serviceConnection)->bustAll();

        return ['whisparr_item_id' => $item['id'] ?? null, 'title' => $item['title'] ?? null, 'tmdb_id' => $tmdbId];
    }

    /**
     * @return array<string, mixed>
     */
    private function monitorItem(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $itemId = (int) ($payload['whisparr_item_id'] ?? 0);
        throw_if($itemId <= 0, InvalidArgumentException::class, 'whisparr_item_id is required');

        $monitored = (bool) ($payload['monitored'] ?? true);
        $serviceConnection = ServiceConnection::resolvePinned($payload, ServiceType::Whisparr);
        $whisparrClient = new WhisparrClient($serviceConnection);
        $item = $whisparrClient->getItemById($itemId);
        $item['monitored'] = $monitored;
        $whisparrClient->updateItem($itemId, $item);
        new WhisparrCache($serviceConnection)->bustAll();

        return ['whisparr_item_id' => $itemId, 'monitored' => $monitored];
    }

    /**
     * @return array<string, mixed>
     */
    private function setQualityProfile(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $itemId = (int) ($payload['whisparr_item_id'] ?? 0);
        $qualityProfileId = (int) ($payload['quality_profile_id'] ?? 0);
        throw_if($itemId <= 0, InvalidArgumentException::class, 'whisparr_item_id is required');
        throw_if($qualityProfileId <= 0, InvalidArgumentException::class, 'quality_profile_id is required');

        $serviceConnection = ServiceConnection::resolvePinned($payload, ServiceType::Whisparr);
        $whisparrClient = new WhisparrClient($serviceConnection);
        $item = $whisparrClient->getItemById($itemId);
        $item['qualityProfileId'] = $qualityProfileId;
        $whisparrClient->updateItem($itemId, $item);
        new WhisparrCache($serviceConnection)->bustAll();

        return ['whisparr_item_id' => $itemId, 'quality_profile_id' => $qualityProfileId];
    }
}
