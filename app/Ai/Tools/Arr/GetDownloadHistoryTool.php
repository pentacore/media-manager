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
 * Read Sonarr/Radarr history (grabs, imports, failures, deletions).
 * Supports filtering by download_id so the agent can trace what happened
 * to one specific download before deciding to import or remove it.
 */
class GetDownloadHistoryTool extends BaseTool
{
    private const int DEFAULT_PAGE_SIZE = 20;

    private const int MAX_PAGE_SIZE = 100;

    public function description(): Stringable|string
    {
        return 'Fetch Sonarr or Radarr history events (grabbed, downloadFolderImported, downloadFailed, '
            .'episodeFileDeleted, ...). Pass download_id to trace a single download\'s lifecycle — do this '
            .'when deciding whether a stuck download is a retry, a duplicate, or a failed import.';
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

        $page = max(1, (int) ($args['page'] ?? 1));
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? self::DEFAULT_PAGE_SIZE)));

        $params = [
            'page' => $page,
            'pageSize' => $pageSize,
            'sortKey' => 'date',
            'sortDirection' => 'descending',
        ];

        $downloadId = (string) ($args['download_id'] ?? '');
        if ($downloadId !== '') {
            $params['downloadId'] = $downloadId;
        }

        $history = $client->getHistory($params);
        $records = is_array($history['records'] ?? null) ? $history['records'] : [];

        return [
            'service' => $service,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => (int) ($history['totalRecords'] ?? count($records)),
            'events' => array_map(fn (array $record): array => $this->project($record, $service), $records),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function project(array $record, string $service): array
    {
        $event = [
            'history_id' => (int) ($record['id'] ?? 0),
            'download_id' => (string) ($record['downloadId'] ?? ''),
            'event_type' => (string) ($record['eventType'] ?? ''),
            'source_title' => (string) ($record['sourceTitle'] ?? ''),
            'date' => (string) ($record['date'] ?? ''),
            'quality' => (string) ($record['quality']['quality']['name'] ?? ''),
            'indexer' => (string) ($record['data']['indexer'] ?? ''),
        ];

        $reason = $record['data']['reason'] ?? $record['data']['message'] ?? null;
        if (is_string($reason) && $reason !== '') {
            $event['reason'] = $reason;
        }

        if ($service === 'sonarr') {
            $event['series'] = isset($record['series']['title']) ? (string) $record['series']['title'] : null;
            $episode = $record['episode'] ?? null;
            $event['episode'] = is_array($episode)
                ? sprintf('S%02dE%02d', (int) ($episode['seasonNumber'] ?? 0), (int) ($episode['episodeNumber'] ?? 0))
                : null;
        } else {
            $event['movie'] = isset($record['movie']['title']) ? (string) $record['movie']['title'] : null;
        }

        return $event;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()
                ->description('Which arr service history to read: "sonarr" or "radarr".')
                ->required(),
            'download_id' => $schema->string()
                ->description('Optional downloadId to trace one download (from GetDownloadQueueTool or a webhook payload).')
                ->required()
                ->nullable(),
            'page' => $schema->integer()
                ->description('Page number, 1-based. Default 1.')
                ->required()
                ->nullable(),
            'page_size' => $schema->integer()
                ->description('Events per page (1-100, default 20).')
                ->required()
                ->nullable(),
        ];
    }
}
