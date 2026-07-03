<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use App\Services\Whisparr\WhisparrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetMediaTool extends BaseTool
{
    private const int DEFAULT_LIMIT = 100;

    private const int MAX_LIMIT = 500;

    public function description(): Stringable|string
    {
        return 'List or count the Sonarr (TV series), Radarr (movie), or Whisparr library. Pass item_id for full details on one item. '
            .'Without item_id, sonarr/radarr return a slim projection from the local index — narrow it with the '
            .'monitored, status, query, and (radarr only) has_file filters, or pass count_only=true for aggregate '
            .'counts (great for "how many unmonitored" questions). Whisparr has no local index: filters are ignored '
            .'and the full library is returned.';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function execute(Request $request): array
    {
        $args = $request->toArray();
        $service = mb_strtolower((string) ($args['service'] ?? ''));
        $itemId = $args['item_id'] ?? null;

        $serviceConnection = ServiceConnection::resolveActive(match ($service) {
            'sonarr' => ServiceType::Sonarr,
            'radarr' => ServiceType::Radarr,
            'whisparr' => ServiceType::Whisparr,
            default => throw new InvalidArgumentException('service must be "sonarr", "radarr", or "whisparr".'),
        });

        if ($service === 'whisparr') {
            $whisparrClient = new WhisparrClient($serviceConnection);

            return $itemId === null ? $whisparrClient->getItems() : $whisparrClient->getItemById((int) $itemId);
        }

        if ($itemId !== null) {
            return $service === 'sonarr'
                ? new SonarrClient($serviceConnection)->getSeriesById((int) $itemId)
                : new RadarrClient($serviceConnection)->getMovieById((int) $itemId);
        }

        return $this->listFromIndex($service, $serviceConnection->id, $args);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function listFromIndex(string $service, int $connectionId, array $args): array
    {
        $builder = $service === 'sonarr'
            ? IndexedSeries::query()->where('service_connection_id', $connectionId)
            : IndexedMovie::query()->where('service_connection_id', $connectionId);

        $monitored = $args['monitored'] ?? null;
        if (is_bool($monitored)) {
            $builder->where('monitored', $monitored);
        }

        $hasFile = $args['has_file'] ?? null;
        if ($service === 'radarr' && is_bool($hasFile)) {
            $builder->where('has_file', $hasFile);
        }

        $status = $args['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $builder->where('status', $status);
        }

        $query = $args['query'] ?? null;
        if (is_string($query) && trim($query) !== '') {
            $builder->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower(trim($query)).'%']);
        }

        if (($args['count_only'] ?? false) === true) {
            return $this->aggregate($service, $connectionId, $builder);
        }

        $limit = max(1, min(self::MAX_LIMIT, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)));
        $total = (clone $builder)->count();
        $rows = $builder->orderBy('title')->limit($limit)->get();

        return [
            'total_matched' => $total,
            'returned' => $rows->count(),
            'truncated' => $total > $rows->count(),
            'items' => $rows->map(static fn (IndexedSeries|IndexedMovie $item): array => $item instanceof IndexedSeries
                ? [
                    'id' => $item->sonarr_id,
                    'tvdb_id' => $item->tvdb_id,
                    'title' => $item->title,
                    'year' => $item->year,
                    'status' => $item->status,
                    'monitored' => $item->monitored,
                    'network' => $item->network,
                    'genres' => $item->genres,
                ]
                : [
                    'id' => $item->radarr_id,
                    'tmdb_id' => $item->tmdb_id,
                    'title' => $item->title,
                    'year' => $item->year,
                    'status' => $item->status,
                    'monitored' => $item->monitored,
                    'has_file' => $item->has_file,
                    'genres' => $item->genres,
                ])->all(),
        ];
    }

    /**
     * @param  Builder<IndexedSeries>|Builder<IndexedMovie>  $builder
     * @return array<string, mixed>
     */
    private function aggregate(string $service, int $connectionId, Builder $builder): array
    {
        $library = $service === 'sonarr'
            ? IndexedSeries::query()->where('service_connection_id', $connectionId)
            : IndexedMovie::query()->where('service_connection_id', $connectionId);

        $aggregates = [
            'matched' => (clone $builder)->count(),
            'library_total' => (clone $library)->count(),
            'library_monitored' => (clone $library)->where('monitored', true)->count(),
            'library_unmonitored' => (clone $library)->where('monitored', false)->count(),
        ];

        if ($service === 'radarr') {
            $aggregates['library_with_file'] = (clone $library)->where('has_file', true)->count();
            $aggregates['library_without_file'] = (clone $library)->where('has_file', false)->count();
        }

        return $aggregates;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service' => $schema->string()
                ->enum(['sonarr', 'radarr', 'whisparr'])
                ->description('Which library to read: sonarr for TV series, radarr for movies, whisparr.')
                ->required(),
            'item_id' => $schema->integer()
                ->description('Service-native id (Sonarr series id / Radarr movie id / Whisparr item id) for full details on one item. Pass null to list / count.')
                ->required()
                ->nullable(),
            'monitored' => $schema->boolean()
                ->description('Filter list by monitored flag. Pass null to skip the filter.')
                ->required()
                ->nullable(),
            'has_file' => $schema->boolean()
                ->description('Radarr only: filter list by whether the movie file is present. Pass null to skip.')
                ->required()
                ->nullable(),
            'status' => $schema->string()
                ->description('Filter list by status (sonarr: continuing, ended, upcoming, deleted; radarr: released, announced, inCinemas, tba). Pass null to skip.')
                ->required()
                ->nullable(),
            'query' => $schema->string()
                ->description('Case-insensitive substring on title. Pass null to skip.')
                ->required()
                ->nullable(),
            'count_only' => $schema->boolean()
                ->description('Return aggregate counts only. Defaults to false. Use this for "how many" questions.')
                ->required()
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Max rows to return when listing (1-500, default 100). Pass null for default.')
                ->required()
                ->nullable(),
        ];
    }
}