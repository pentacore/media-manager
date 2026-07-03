<?php

declare(strict_types=1);

namespace App\Ai\Tools\System;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Services\Search\SemanticLibrarySearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class SemanticLibrarySearchTool extends BaseTool
{
    public function __construct(private readonly SemanticLibrarySearch $semanticLibrarySearch) {}

    public function description(): Stringable|string
    {
        return 'Semantic (meaning-based) search over the local movie/series library. Use for vibe or '
            .'similarity queries ("something like Dark but lighter", "cozy detective shows") where keyword '
            .'search fails. Returns scored matches from the indexed library only — not from TMDB/Trakt.';
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
        $query = trim((string) ($args['query'] ?? ''));

        if ($query === '') {
            return ['error' => 'empty_query', 'message' => 'query is required.'];
        }

        $limit = max(1, min(25, (int) ($args['limit'] ?? 10)));
        $kind = in_array($args['kind'] ?? null, ['movie', 'series'], true) ? $args['kind'] : null;

        $result = $this->semanticLibrarySearch->search($query, $limit, $kind);

        if (! $result['available']) {
            return [
                'available' => false,
                'message' => 'Semantic search is unavailable (AI disabled or search index not ready). Fall back to GetMediaTool/SearchMediaTool keyword filters.',
            ];
        }

        return $result;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Natural-language description of what to find (themes, mood, similar titles).')
                ->required(),
            'limit' => $schema->integer()
                ->description('Max results (1-25, default 10). Pass null for default.')
                ->required()
                ->nullable(),
            'kind' => $schema->string()
                ->description('Restrict to "movie" or "series". Pass null for both.')
                ->required()
                ->nullable(),
        ];
    }
}
