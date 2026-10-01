<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Enums\LibraryBulkAction;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ManualActionDispatcher;
use App\Services\Actions\ManualActionOutcome;
use App\Services\Audit\AuditLogger;
use App\Services\MediaReplacement\PendingReplacementGuard;
use InvalidArgumentException;

/**
 * The single-item library actions a person takes on a Sonarr series, a Radarr
 * movie or a Whisparr item: monitor, quality profile, search and delete. The
 * title pages and the bulk endpoints both call these methods, so the
 * replacement guard, the pinned payload, the Action Queue dispatch (Action
 * Rules, approval, activity rows) and the delete audit row are the same for
 * one title and for a hundred.
 */
final readonly class LibraryActionRequester
{
    public function __construct(
        private ManualActionDispatcher $manualActionDispatcher,
        private PendingReplacementGuard $pendingReplacementGuard,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * One bulk library action on one title — the same method the title
     * page's button calls.
     */
    public function apply(LibraryBulkAction $libraryBulkAction, ServiceConnection $serviceConnection, int $itemId, ?int $qualityProfileId, bool $deleteFiles, string $because): ManualActionOutcome
    {
        return match ($libraryBulkAction) {
            LibraryBulkAction::Monitor => $this->monitor($serviceConnection, $itemId, true, $because),
            LibraryBulkAction::Unmonitor => $this->monitor($serviceConnection, $itemId, false, $because),
            LibraryBulkAction::QualityProfile => $this->setQualityProfile($serviceConnection, $itemId, (int) $qualityProfileId, $because),
            LibraryBulkAction::Search => $this->search($serviceConnection, $itemId, $because),
            LibraryBulkAction::Delete => $this->delete($serviceConnection, $itemId, $deleteFiles, $because),
        };
    }

    public function monitor(ServiceConnection $serviceConnection, int $itemId, bool $monitored, string $because): ManualActionOutcome
    {
        $serviceType = $this->serviceType($serviceConnection);

        if ($this->replacementInFlight($serviceConnection, $itemId)) {
            return new ManualActionOutcome(state: ManualActionOutcome::BLOCKED);
        }

        return $this->manualActionDispatcher->dispatch(
            match ($serviceType) {
                ServiceType::Sonarr => 'monitor_series',
                ServiceType::Radarr => 'monitor_movie',
                default => 'whisparr_monitor_item',
            },
            $serviceType,
            [$this->itemKey($serviceType) => $itemId, 'monitored' => $monitored, 'service_connection_id' => $serviceConnection->id],
            $because,
        );
    }

    public function setQualityProfile(ServiceConnection $serviceConnection, int $itemId, int $qualityProfileId, string $because): ManualActionOutcome
    {
        $serviceType = $this->serviceType($serviceConnection);

        return $this->manualActionDispatcher->dispatch(
            match ($serviceType) {
                ServiceType::Sonarr => 'set_series_quality_profile',
                ServiceType::Radarr => 'set_movie_quality_profile',
                default => 'whisparr_set_quality_profile',
            },
            $serviceType,
            [$this->itemKey($serviceType) => $itemId, 'quality_profile_id' => $qualityProfileId, 'service_connection_id' => $serviceConnection->id],
            $because,
        );
    }

    public function search(ServiceConnection $serviceConnection, int $itemId, string $because): ManualActionOutcome
    {
        $serviceType = $this->serviceType($serviceConnection);

        [$type, $payload] = match ($serviceType) {
            ServiceType::Sonarr => ['search_media', ['service' => 'sonarr', 'command' => 'series_search', 'series_id' => $itemId]],
            ServiceType::Radarr => ['search_media', ['service' => 'radarr', 'command' => 'movies_search', 'movie_ids' => [$itemId]]],
            default => ['whisparr_search', ['whisparr_item_id' => $itemId]],
        };

        return $this->manualActionDispatcher->dispatch(
            $type,
            $serviceType,
            [...$payload, 'service_connection_id' => $serviceConnection->id],
            $because,
        );
    }

    public function delete(ServiceConnection $serviceConnection, int $itemId, bool $deleteFiles, string $because): ManualActionOutcome
    {
        $serviceType = $this->serviceType($serviceConnection);

        // Action names and context shapes match 7a's SeriesController/
        // MovieController::destroy() exactly (series.delete_requested /
        // movie.delete_requested); Whisparr uses the spec's whisparr.deleted.
        [$type, $idKey, $auditAction] = match ($serviceType) {
            ServiceType::Sonarr => ['delete_series', 'sonarr_series_id', 'series.delete_requested'],
            ServiceType::Radarr => ['delete_movie', 'radarr_movie_id', 'movie.delete_requested'],
            default => ['whisparr_delete_item', 'whisparr_item_id', 'whisparr.deleted'],
        };

        $manualActionOutcome = $this->manualActionDispatcher->dispatch($type, $serviceType, [
            $idKey => $itemId,
            'delete_files' => $deleteFiles,
            'service_connection_id' => $serviceConnection->id,
        ], $because);

        // A person asked for this delete (the agent never comes through
        // here): one audit row per filed request, bulk included.
        if ($manualActionOutcome->actionRequest instanceof ActionRequest) {
            $this->auditLogger->record(
                $auditAction,
                $manualActionOutcome->actionRequest,
                (string) $manualActionOutcome->actionRequest->title,
                context: [
                    $idKey => $itemId,
                    'delete_files' => $deleteFiles,
                    'service_connection_id' => $serviceConnection->id,
                ],
            );
        }

        return $manualActionOutcome;
    }

    private function serviceType(ServiceConnection $serviceConnection): ServiceType
    {
        $serviceType = $serviceConnection->type;

        throw_unless(
            in_array($serviceType, [ServiceType::Sonarr, ServiceType::Radarr, ServiceType::Whisparr], true),
            InvalidArgumentException::class,
            sprintf('%s has no library actions.', $serviceType->label()),
        );

        return $serviceType;
    }

    private function itemKey(ServiceType $serviceType): string
    {
        return match ($serviceType) {
            ServiceType::Sonarr => 'series_id',
            ServiceType::Radarr => 'movie_id',
            default => 'whisparr_item_id',
        };
    }

    private function replacementInFlight(ServiceConnection $serviceConnection, int $itemId): bool
    {
        return match ($serviceConnection->type) {
            ServiceType::Sonarr => $this->pendingReplacementGuard->inFlightForMedia($serviceConnection->id, seriesId: $itemId),
            ServiceType::Radarr => $this->pendingReplacementGuard->inFlightForMedia($serviceConnection->id, movieId: $itemId),
            default => false,
        };
    }
}
