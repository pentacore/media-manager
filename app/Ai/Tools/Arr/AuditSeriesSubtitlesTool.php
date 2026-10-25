<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\SeriesSubtitleAuditor;
use App\Services\MediaReplacement\SeriesSubtitleAuditUnavailable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class AuditSeriesSubtitlesTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Audit every episode of an installed Sonarr series (or one season) for missing subtitles. Read-only: it reads '
            .'the subtitle tracks Sonarr already reports for each episode file and never searches or changes anything. Returns the '
            .'counts, the episodes missing a required language and the skipped episodes with their reasons. The series_id is the Sonarr '
            .'series id from SearchMediaTool; never guess it. Specials (season 0) are only audited when season_number is 0.';
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
        $args = $request->validate([
            'series_id' => ['required', 'integer', 'min:1'],
            'season_number' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            return resolve(SeriesSubtitleAuditor::class)->audit(
                ServiceConnection::resolveActive(ServiceType::Sonarr),
                (int) $args['series_id'],
                isset($args['season_number']) ? (int) $args['season_number'] : null,
            )->toArray();
        } catch (SeriesSubtitleAuditUnavailable $seriesSubtitleAuditUnavailable) {
            return [
                'error' => $seriesSubtitleAuditUnavailable->reason,
                'message' => 'The series root folder has no anime/tv scope configured. Tell the user to set it in the Sonarr connection settings.',
            ];
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'series_id' => $schema->integer()
                ->description('The Sonarr series id, taken from SearchMediaTool. Never guess it.')
                ->required(),
            'season_number' => $schema->integer()
                ->description('Audit only this season (0 for specials). Pass null for the whole series.')
                ->required()
                ->nullable(),
        ];
    }
}
