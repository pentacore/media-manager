<?php

declare(strict_types=1);

namespace App\Ai\Tools\Bazarr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Http\Resources\Bazarr\SubtitleCandidateResource;
use App\Models\ServiceConnection;
use App\Services\Bazarr\BazarrClient;
use App\Services\Bazarr\SubtitleInventoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;

final class SearchSubtitlesTool extends BaseTool
{
    public function description(): string
    {
        return 'Search Bazarr for subtitle candidates for one exact episode or movie. Returns at most ten sanitized '
            .'candidates with opaque fingerprints; never returns paths, provider URLs, credentials, or subtitle text.';
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
        $arguments = $request->toArray();
        $connection = $this->connection((int) ($arguments['bazarr_connection_id'] ?? 0));
        $mediaType = (string) ($arguments['media_type'] ?? '');
        $mediaId = (int) ($arguments['media_id'] ?? 0);
        $limit = is_numeric($arguments['limit'] ?? null) ? (int) $arguments['limit'] : 5;

        throw_unless($limit >= 1 && $limit <= 10, InvalidArgumentException::class, 'Search limit must be between 1 and 10.');

        $inspection = resolve(SubtitleInventoryService::class)->inspect($connection, $mediaType, $mediaId);
        $bazarrClient = new BazarrClient($connection);
        $candidates = $mediaType === 'episode'
            ? $bazarrClient->searchEpisode($mediaId)
            : $bazarrClient->searchMovie($mediaId);

        return [
            'item' => $inspection['item'],
            'candidates' => array_map(
                static fn (array $candidate): array => new SubtitleCandidateResource($candidate)->resolve(),
                array_slice($candidates, 0, $limit),
            ),
            'candidate_count' => min(count($candidates), $limit),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'bazarr_connection_id' => $schema->integer()
                ->description('The exact active Bazarr connection ID.')
                ->required(),
            'media_type' => $schema->string()
                ->enum(['episode', 'movie'])
                ->description('The exact Bazarr media type.')
                ->required(),
            'media_id' => $schema->integer()
                ->description('The exact Sonarr episode ID or Radarr movie ID known to Bazarr.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum candidates to return, from 1 to 10. Pass null for 5.')
                ->required()
                ->nullable(),
        ];
    }

    private function connection(int $connectionId): ServiceConnection
    {
        return ServiceConnection::query()
            ->whereKey($connectionId)
            ->where('type', ServiceType::Bazarr)
            ->where('is_active', true)
            ->firstOrFail();
    }
}
