<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\MediaReplacementScope;

/**
 * The read-only result of auditing a series (or one season) for missing subtitles.
 */
final readonly class SeriesSubtitleAudit
{
    /**
     * @param  list<SeriesSubtitleAuditEpisode>  $episodes
     */
    public function __construct(
        public int $serviceConnectionId,
        public int $seriesId,
        public string $seriesTitle,
        public MediaReplacementScope $scope,
        public ?int $seasonNumber,
        public array $episodes,
    ) {}

    /**
     * @return list<SeriesSubtitleAuditEpisode>
     */
    public function missing(): array
    {
        return $this->withStatus(SeriesSubtitleAuditEpisode::STATUS_MISSING);
    }

    /**
     * @return array{series_id: int, series_title: string, scope: string, season_number: int|null, counts: array{total: int, ok: int, missing: int, skipped: int}, missing: list<array{label: string, missing_languages: list<string>}>, skipped: list<array{label: string, reason: string}>}
     */
    public function toArray(): array
    {
        $skipped = $this->withStatus(SeriesSubtitleAuditEpisode::STATUS_SKIPPED);
        $missing = $this->missing();

        return [
            'series_id' => $this->seriesId,
            'series_title' => $this->seriesTitle,
            'scope' => $this->scope->value,
            'season_number' => $this->seasonNumber,
            'counts' => [
                'total' => count($this->episodes),
                'ok' => count($this->withStatus(SeriesSubtitleAuditEpisode::STATUS_OK)),
                'missing' => count($missing),
                'skipped' => count($skipped),
            ],
            'missing' => array_map(
                static fn (SeriesSubtitleAuditEpisode $episode): array => [
                    'label' => $episode->label(),
                    'missing_languages' => $episode->missingLanguages,
                ],
                $missing,
            ),
            'skipped' => array_map(
                static fn (SeriesSubtitleAuditEpisode $episode): array => [
                    'label' => $episode->label(),
                    'reason' => (string) $episode->skipReason,
                ],
                $skipped,
            ),
        ];
    }

    /**
     * @return list<SeriesSubtitleAuditEpisode>
     */
    private function withStatus(string $status): array
    {
        return array_values(array_filter(
            $this->episodes,
            static fn (SeriesSubtitleAuditEpisode $episode): bool => $episode->status === $status,
        ));
    }
}
