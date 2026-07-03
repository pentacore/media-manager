<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Read the Sonarr/Radarr download queue. Slim projection with a `stuck`
 * flag so the agent can spot downloads needing manual intervention and
 * chain into InspectStuckImportTool.
 */
class GetDownloadQueueTool extends BaseTool
{
    private const int MAX_PAGE_SIZE = 200;

    public function description(): Stringable|string
    {
        return 'List the Sonarr or Radarr download queue. Each item includes download_id, status, and any '
            .'status_messages (rejection reasons). Items with stuck=true need manual intervention — inspect '
            .'them with InspectStuckImportTool before importing or removing. Use stuck_only=true to list only '
            .'downloads that are stuck.';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();
        $service = mb_strtolower((string) ($args['service'] ?? ''));

        $type = match ($service) {
            'sonarr' => ServiceType::Sonarr,
            'radarr' => ServiceType::Radarr,
            default => throw new InvalidArgumentException('service must be "sonarr" or "radarr".'),
        };

        $serviceConnection = ServiceConnection::resolveActive($type);
        $client = $type === ServiceType::Sonarr
            ? new SonarrClient($serviceConnection)
            : new RadarrClient($serviceConnection);

        $params = [
            'page' => 1,
            'pageSize' => self::MAX_PAGE_SIZE,
        ];
        $params[$type === ServiceType::Sonarr ? 'includeUnknownSeriesItems' : 'includeUnknownMovieItems'] = 'true';

        $queue = $client->getQueue($params);
        $records = is_array($queue['records'] ?? null) ? $queue['records'] : [];

        $items = array_map(fn (array $record): array => $this->project($record, $service), $records);

        if (($args['stuck_only'] ?? false) === true) {
            $items = array_values(array_filter($items, static fn (array $item): bool => $item['stuck']));
        }

        return [
            'service' => $service,
            'total' => (int) ($queue['totalRecords'] ?? count($records)),
            'returned' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function project(array $record, string $service): array
    {
        $messages = [];
        foreach ((array) ($record['statusMessages'] ?? []) as $statusMessage) {
            foreach ((array) ($statusMessage['messages'] ?? []) as $message) {
                if (is_string($message) && $message !== '') {
                    $messages[] = $message;
                }
            }
        }

        $stuck = ($record['trackedDownloadStatus'] ?? '') === 'warning' || $messages !== [];

        $item = [
            'queue_id' => (int) ($record['id'] ?? 0),
            'download_id' => (string) ($record['downloadId'] ?? ''),
            'title' => (string) ($record['title'] ?? ''),
            'status' => (string) ($record['status'] ?? ''),
            'tracked_download_state' => (string) ($record['trackedDownloadState'] ?? ''),
            'protocol' => (string) ($record['protocol'] ?? ''),
            'indexer' => (string) ($record['indexer'] ?? ''),
            'size_bytes' => (int) ($record['size'] ?? 0),
            'timeleft' => (string) ($record['timeleft'] ?? ''),
            'stuck' => $stuck,
            'status_messages' => $messages,
        ];

        if ($service === 'sonarr') {
            $item['series'] = isset($record['series']['title']) ? (string) $record['series']['title'] : null;
            $episode = $record['episode'] ?? null;
            $item['episode'] = is_array($episode)
                ? sprintf('S%02dE%02d', (int) ($episode['seasonNumber'] ?? 0), (int) ($episode['episodeNumber'] ?? 0))
                : null;
        } else {
            $item['movie'] = isset($record['movie']['title']) ? (string) $record['movie']['title'] : null;
        }

        return $item;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()
                ->description('Which arr service queue to read: "sonarr" or "radarr".')
                ->required(),
            'stuck_only' => $schema->boolean()
                ->description('Only return items needing manual intervention (warnings / rejection messages). Default false.')
                ->required()
                ->nullable(),
        ];
    }
}
