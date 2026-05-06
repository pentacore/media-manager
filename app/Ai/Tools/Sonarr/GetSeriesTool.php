<?php

declare(strict_types=1);

namespace App\Ai\Tools\Sonarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetSeriesTool extends BaseTool
{
    private const int DEFAULT_LIMIT = 100;

    private const int MAX_LIMIT = 500;

    public function description(): Stringable|string
    {
        return 'List or count Sonarr series. Pass series_id for full details on one series. '
            .'Without series_id, returns a slim projection (id, title, year, status, monitored, network, genres, runtime) '
            .'from the local index. Use filters (monitored, status, query) to narrow the list. '
            .'Use count_only=true to return just aggregate counts (great for "how many unmonitored" style questions).';
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
        $seriesId = $args['series_id'] ?? null;

        if ($seriesId !== null) {
            $serviceConnection = ServiceConnection::resolveActive(ServiceType::Sonarr);

            return new SonarrClient($serviceConnection)->getSeriesById((int) $seriesId);
        }

        $serviceConnection = ServiceConnection::resolveActive(ServiceType::Sonarr);

        $base = IndexedSeries::query()->where('service_connection_id', $serviceConnection->id);

        $monitored = $args['monitored'] ?? null;
        if (is_bool($monitored)) {
            $base->where('monitored', $monitored);
        }

        $status = $args['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $base->where('status', $status);
        }

        $query = $args['query'] ?? null;
        if (is_string($query) && trim($query) !== '') {
            $base->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower(trim($query)).'%']);
        }

        if (($args['count_only'] ?? false) === true) {
            return $this->aggregate($serviceConnection->id, $base);
        }

        $limit = max(1, min(self::MAX_LIMIT, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)));
        $total = (clone $base)->count();
        $rows = $base->orderBy('title')->limit($limit)->get();

        return [
            'total_matched' => $total,
            'returned' => $rows->count(),
            'truncated' => $total > $rows->count(),
            'series' => $rows->map(static fn (IndexedSeries $row): array => [
                'id' => $row->sonarr_id,
                'tvdb_id' => $row->tvdb_id,
                'title' => $row->title,
                'year' => $row->year,
                'status' => $row->status,
                'monitored' => $row->monitored,
                'network' => $row->network,
                'genres' => $row->genres,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregate(int $connectionId, Builder $filtered): array
    {
        $matched = (clone $filtered)->count();
        $library = IndexedSeries::query()->where('service_connection_id', $connectionId);

        return [
            'matched' => $matched,
            'library_total' => (clone $library)->count(),
            'library_monitored' => (clone $library)->where('monitored', true)->count(),
            'library_unmonitored' => (clone $library)->where('monitored', false)->count(),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'series_id' => $schema->integer()
                ->description('Sonarr series id for full details on one series. Pass null to list / count.')
                ->required()
                ->nullable(),
            'monitored' => $schema->boolean()
                ->description('Filter list by monitored flag. Pass null to skip the filter.')
                ->required()
                ->nullable(),
            'status' => $schema->string()
                ->description('Filter list by Sonarr status (continuing, ended, upcoming, deleted). Pass null to skip.')
                ->required()
                ->nullable(),
            'query' => $schema->string()
                ->description('Case-insensitive substring on title. Pass null to skip.')
                ->required()
                ->nullable(),
            'count_only' => $schema->boolean()
                ->description('Return aggregate counts only (matched, library_total, library_monitored, library_unmonitored). Defaults to false. Use this for "how many" questions.')
                ->required()
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Max rows to return when listing (1-500, default 100). Pass null for default.')
                ->required()
                ->nullable(),
        ];
    }
}
