<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

/**
 * One episode's verdict in a series subtitle audit.
 */
final readonly class SeriesSubtitleAuditEpisode
{
    public const string STATUS_OK = 'ok';

    public const string STATUS_MISSING = 'missing';

    public const string STATUS_SKIPPED = 'skipped';

    /**
     * @param  'ok'|'missing'|'skipped'  $status
     * @param  list<string>  $missingLanguages
     */
    public function __construct(
        public int $episodeId,
        public int $seasonNumber,
        public int $episodeNumber,
        public string $status,
        public array $missingLanguages = [],
        public ?string $skipReason = null,
    ) {}

    public function label(): string
    {
        return sprintf('S%02dE%02d', $this->seasonNumber, $this->episodeNumber);
    }
}
