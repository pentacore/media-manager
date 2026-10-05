<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Models\ServiceConnection;
use App\Models\SubtitleCase;

/**
 * Temporary facade while callers move to the collaborators it delegates to:
 * SubtitleLibraryReader (overview, library, missing, history), SubtitleInspector
 * (inspect) and SubtitleCaseCandidates (case identities). Removed once no
 * caller is left.
 */
final readonly class SubtitleInventoryService
{
    public function __construct(
        private SubtitleCaseCandidates $subtitleCaseCandidates,
        private SubtitleLibraryReader $subtitleLibraryReader,
        private SubtitleInspector $subtitleInspector,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, page: int, per_page: int, total: int, partial: bool, errors: list<string>}
     */
    public function caseCandidates(
        ServiceConnection $serviceConnection,
        int $page = 1,
        int $perPage = SubtitleLibraryReader::MAX_PER_PAGE,
    ): array {
        return $this->subtitleCaseCandidates->caseCandidates($serviceConnection, $page, $perPage);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function caseCandidateFor(SubtitleCase $subtitleCase): ?array
    {
        return $this->subtitleCaseCandidates->caseCandidateFor($subtitleCase);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function caseCandidateForMedia(
        ServiceConnection $serviceConnection,
        string $mediaType,
        int $mediaId,
    ): ?array {
        return $this->subtitleCaseCandidates->caseCandidateForMedia($serviceConnection, $mediaType, $mediaId);
    }

    /**
     * @return array{
     *     missing: array{episodes: int, movies: int, total: int},
     *     health_issue_count: int,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    public function overview(ServiceConnection $serviceConnection): array
    {
        return $this->subtitleLibraryReader->overview($serviceConnection);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, page: int, per_page: int, total: int, partial: bool, errors: list<string>}
     */
    public function library(ServiceConnection $serviceConnection, int $page, int $perPage, array $filters = []): array
    {
        return $this->subtitleLibraryReader->library($serviceConnection, $page, $perPage, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, page: int, per_page: int, total: int, partial: bool, errors: list<string>}
     */
    public function missing(ServiceConnection $serviceConnection, int $page, int $perPage, array $filters = []): array
    {
        return $this->subtitleLibraryReader->missing($serviceConnection, $page, $perPage, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, page: int, per_page: int, total: int, partial: bool, errors: list<string>}
     */
    public function history(ServiceConnection $serviceConnection, int $page, int $perPage, array $filters = []): array
    {
        return $this->subtitleLibraryReader->history($serviceConnection, $page, $perPage, $filters);
    }

    /**
     * @return array{item: array<string, mixed>, history: list<array<string, mixed>>, partial: bool, errors: list<string>}
     */
    public function inspect(ServiceConnection $serviceConnection, string $mediaType, int $mediaId): array
    {
        return $this->subtitleInspector->inspect($serviceConnection, $mediaType, $mediaId);
    }
}
