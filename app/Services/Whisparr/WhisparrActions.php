<?php

declare(strict_types=1);

namespace App\Services\Whisparr;

use App\Cache\Services\WhisparrCache;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use App\Support\PayloadInt;
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
            'whisparr_search' => $this->search($actionRequest),
            default => throw new InvalidArgumentException(sprintf('WhisparrActions cannot execute type "%s"', $actionRequest->type)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteItem(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $itemId = PayloadInt::required($payload, 'whisparr_item_id');

        $deleteFiles = (bool) ($payload['delete_files'] ?? false);
        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, ServiceType::Whisparr);
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
        $tmdbId = PayloadInt::required($payload, 'tmdb_id');

        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, ServiceType::Whisparr);
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
        $itemId = PayloadInt::required($payload, 'whisparr_item_id');

        $monitored = (bool) ($payload['monitored'] ?? true);
        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, ServiceType::Whisparr);
        $whisparrClient = new WhisparrClient($serviceConnection);
        $item = $whisparrClient->fetchItemById($itemId);
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
        $itemId = PayloadInt::required($payload, 'whisparr_item_id');
        $qualityProfileId = PayloadInt::required($payload, 'quality_profile_id');

        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, ServiceType::Whisparr);
        $whisparrClient = new WhisparrClient($serviceConnection);
        $item = $whisparrClient->fetchItemById($itemId);
        $item['qualityProfileId'] = $qualityProfileId;
        $whisparrClient->updateItem($itemId, $item);
        new WhisparrCache($serviceConnection)->bustAll();

        return ['whisparr_item_id' => $itemId, 'quality_profile_id' => $qualityProfileId];
    }

    /**
     * @return array<string, mixed>
     */
    private function search(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $itemId = PayloadInt::required($payload, 'whisparr_item_id');

        // Item ids overlap between Whisparr instances: a search only ever
        // runs against the pinned connection, never "the active one".
        $serviceConnection = ServiceConnection::resolvePinnedStrict($payload, ServiceType::Whisparr);
        $response = new WhisparrClient($serviceConnection)->searchItem($itemId);

        return ['whisparr_item_id' => $itemId, 'whisparr_command_id' => $response['id'] ?? null];
    }
}
