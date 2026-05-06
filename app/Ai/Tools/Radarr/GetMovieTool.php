<?php

declare(strict_types=1);

namespace App\Ai\Tools\Radarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\IndexedMovie;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetMovieTool extends BaseTool
{
    private const int DEFAULT_LIMIT = 100;

    private const int MAX_LIMIT = 500;

    public function description(): Stringable|string
    {
        return 'List or count Radarr movies. Pass movie_id for full details on one movie. '
            .'Without movie_id, returns a slim projection (id, title, year, status, monitored, has_file, genres) '
            .'from the local index. Use filters (monitored, has_file, status, query) to narrow the list. '
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
        $movieId = $args['movie_id'] ?? null;

        if ($movieId !== null) {
            $serviceConnection = ServiceConnection::resolveActive(ServiceType::Radarr);

            return new RadarrClient($serviceConnection)->getMovieById((int) $movieId);
        }

        $serviceConnection = ServiceConnection::resolveActive(ServiceType::Radarr);

        $builder = IndexedMovie::query()->where('service_connection_id', $serviceConnection->id);

        $monitored = $args['monitored'] ?? null;
        if (is_bool($monitored)) {
            $builder->where('monitored', $monitored);
        }

        $hasFile = $args['has_file'] ?? null;
        if (is_bool($hasFile)) {
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
            return $this->aggregate($serviceConnection->id, $builder);
        }

        $limit = max(1, min(self::MAX_LIMIT, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)));
        $total = (clone $builder)->count();
        $rows = $builder->orderBy('title')->limit($limit)->get();

        return [
            'total_matched' => $total,
            'returned' => $rows->count(),
            'truncated' => $total > $rows->count(),
            'movies' => $rows->map(static fn (IndexedMovie $indexedMovie): array => [
                'id' => $indexedMovie->radarr_id,
                'tmdb_id' => $indexedMovie->tmdb_id,
                'title' => $indexedMovie->title,
                'year' => $indexedMovie->year,
                'status' => $indexedMovie->status,
                'monitored' => $indexedMovie->monitored,
                'has_file' => $indexedMovie->has_file,
                'genres' => $indexedMovie->genres,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregate(int $connectionId, Builder $builder): array
    {
        $matched = (clone $builder)->count();
        $library = IndexedMovie::query()->where('service_connection_id', $connectionId);

        return [
            'matched' => $matched,
            'library_total' => (clone $library)->count(),
            'library_monitored' => (clone $library)->where('monitored', true)->count(),
            'library_unmonitored' => (clone $library)->where('monitored', false)->count(),
            'library_with_file' => (clone $library)->where('has_file', true)->count(),
            'library_without_file' => (clone $library)->where('has_file', false)->count(),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'movie_id' => $schema->integer()
                ->description('Radarr movie id for full details on one movie. Pass null to list / count.')
                ->required()
                ->nullable(),
            'monitored' => $schema->boolean()
                ->description('Filter list by monitored flag. Pass null to skip the filter.')
                ->required()
                ->nullable(),
            'has_file' => $schema->boolean()
                ->description('Filter list by whether the movie file is present. Pass null to skip.')
                ->required()
                ->nullable(),
            'status' => $schema->string()
                ->description('Filter list by Radarr status (released, announced, inCinemas, tba). Pass null to skip.')
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
