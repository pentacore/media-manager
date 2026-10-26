<?php

declare(strict_types=1);

namespace App\Ai\Tools\Arr;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Enums\AiMode;
use App\Enums\ServiceType;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Models\ServiceConnection;
use App\Services\Chat\SubtitleAuditBatchCard;
use App\Services\MediaReplacement\SeriesSubtitleAuditor;
use App\Services\MediaReplacement\SeriesSubtitleAuditUnavailable;
use App\Services\MediaReplacement\SeriesSubtitleRepairer;
use App\Services\MediaReplacement\SeriesSubtitleRepairRefused;
use App\Services\MediaReplacement\SubtitleAuditBatchSettler;
use App\Services\MediaReplacement\SubtitleAuditBatchWaiter;
use App\Settings\AiSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class RepairSeriesSubtitlesTool extends BaseTool
{
    /** How long the chat turn waits for the release searches before handing the batch off. */
    public const int IN_CHAT_WAIT_SECONDS = 60;

    /** Time the turn keeps after the wait to answer before the request ceiling. */
    private const int REQUEST_MARGIN_SECONDS = 30;

    public function description(): Stringable|string
    {
        return 'Repair the missing subtitles of an installed Sonarr series (or one season) as ONE approvable batch. Audits the '
            .'series, searches releases for every missing episode and queues each confident match as a pending replacement the '
            .'user approves together. Nothing is replaced until they approve. Returns a batch card, or handed_off when the searches '
            .'need longer than this turn. Never call ReplaceMediaFileTool once per episode instead. The series_id is the Sonarr '
            .'series id from SearchMediaTool; never guess it.';
    }

    public function risk(): Risk
    {
        return Risk::SafeWrite;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        if (resolve(AiSettings::class)->mode() === AiMode::Advisory) {
            return json_decode(self::ADVISORY_REFUSAL, true, flags: JSON_THROW_ON_ERROR);
        }

        $args = $request->validate([
            'series_id' => ['required', 'integer', 'min:1'],
            'season_number' => ['nullable', 'integer', 'min:0'],
        ]);

        $user = auth()->user();

        if ($user === null) {
            return ['error' => 'no_user'];
        }

        try {
            $seriesSubtitleAudit = resolve(SeriesSubtitleAuditor::class)->audit(
                ServiceConnection::resolveActive(ServiceType::Sonarr),
                (int) $args['series_id'],
                isset($args['season_number']) ? (int) $args['season_number'] : null,
            );
        } catch (SeriesSubtitleAuditUnavailable $seriesSubtitleAuditUnavailable) {
            return AuditSeriesSubtitlesTool::unavailableResult($seriesSubtitleAuditUnavailable);
        }

        try {
            $subtitleAuditBatch = resolve(SeriesSubtitleRepairer::class)->start($seriesSubtitleAudit, $user);
        } catch (SeriesSubtitleRepairRefused $seriesSubtitleRepairRefused) {
            return [
                'repaired' => false,
                'reason' => $seriesSubtitleRepairRefused->reason,
                'message' => $this->refusalMessage($seriesSubtitleRepairRefused->reason),
            ];
        }

        $ready = resolve(SubtitleAuditBatchWaiter::class)->waitForReady($subtitleAuditBatch, $this->inChatWaitSeconds());
        $subtitleAuditBatchSettler = resolve(SubtitleAuditBatchSettler::class);

        // Exactly one of card and notification: the card only for a batch this
        // turn claims (ready, nobody handed it off); otherwise it is handed off,
        // by this turn or already by the watchdog, and admins get notified.
        if ($ready && $subtitleAuditBatchSettler->claimCard($subtitleAuditBatch)) {
            return ['subtitle_audit_batch' => SubtitleAuditBatchCard::present($subtitleAuditBatch)];
        }

        if (! $subtitleAuditBatchSettler->handOff($subtitleAuditBatch) && $subtitleAuditBatchSettler->claimCard($subtitleAuditBatch)) {
            return ['subtitle_audit_batch' => SubtitleAuditBatchCard::present($subtitleAuditBatch)];
        }

        return [
            'handed_off' => true,
            'subtitle_audit_batch_id' => $subtitleAuditBatch->id,
            'searching' => $subtitleAuditBatch->episodes()->where('outcome', SubtitleAuditEpisodeOutcome::Searching->value)->count(),
            'message' => 'The release searches continue in the background. Tell the user they will be notified when the batch is ready to approve on the Actions page.',
            // The card as it stands; reopening the conversation shows it afresh.
            'subtitle_audit_batch' => SubtitleAuditBatchCard::present($subtitleAuditBatch),
        ];
    }

    /**
     * The chat turn runs inside the HTTP request, which Octane kills at its
     * ceiling: wait at most what is left of it, less a 30 second margin for the
     * rest of the turn, and never longer than IN_CHAT_WAIT_SECONDS. Without a
     * ceiling or a known request start, wait the full time.
     */
    private function inChatWaitSeconds(): int
    {
        $ceiling = (int) config('octane.max_execution_time', 0);
        $requestStartedAt = request()->server('REQUEST_TIME_FLOAT');

        if ($ceiling <= 0 || ! is_numeric($requestStartedAt)) {
            return self::IN_CHAT_WAIT_SECONDS;
        }

        $remaining = (int) floor($ceiling - (microtime(true) - (float) $requestStartedAt) - self::REQUEST_MARGIN_SECONDS);

        return min(self::IN_CHAT_WAIT_SECONDS, max(0, $remaining));
    }

    private function refusalMessage(string $reason): string
    {
        return match ($reason) {
            'nothing_missing' => 'No episode is missing a required subtitle, so there is nothing to repair. Tell the user.',
            'action_type_disabled' => 'The replace_media_file action type is disabled. Tell the user to enable it in the action rules.',
            'batch_in_progress' => 'A subtitle repair for this series is already searching. Tell the user to wait for its notification.',
            default => 'The repair could not start. Tell the user.',
        };
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
                ->description('Repair only this season (0 for specials). Pass null for the whole series.')
                ->required()
                ->nullable(),
        ];
    }
}
