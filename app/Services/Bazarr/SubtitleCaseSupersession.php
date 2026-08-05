<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Enums\SubtitleCaseStatus;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use Illuminate\Database\Eloquent\Builder;

/**
 * Supersedes active subtitle cases when the topology that produced them is torn
 * down: a mapping is removed or replaced, or a Bazarr / managing connection is
 * deactivated, deleted, or retyped. Never touches subtitles or media files, and
 * never regresses a terminal case.
 */
final readonly class SubtitleCaseSupersession
{
    /** @var list<SubtitleCaseStatus> */
    private const array ACTIVE_STATUSES = [
        SubtitleCaseStatus::Observing,
        SubtitleCaseStatus::BazarrSearching,
        SubtitleCaseStatus::DownloadRequested,
        SubtitleCaseStatus::ReplacementEligible,
        SubtitleCaseStatus::AdvisorRunning,
        SubtitleCaseStatus::ReplacementRequested,
        SubtitleCaseStatus::NeedsReview,
    ];

    public function __construct(
        private SubtitleCaseLifecycle $subtitleCaseLifecycle,
    ) {}

    /**
     * Supersede every active case where this connection is either the Bazarr or
     * the managing service connection. Used when a connection is deactivated,
     * deleted, or retyped.
     */
    public function forConnection(ServiceConnection $serviceConnection): int
    {
        return $this->supersede(
            SubtitleCase::query()->where(function (Builder $builder) use ($serviceConnection): void {
                $builder->where('bazarr_connection_id', $serviceConnection->id)
                    ->orWhere('service_connection_id', $serviceConnection->id);
            }),
        );
    }

    /**
     * Supersede every active case for one Bazarr-to-managing pairing. Used when a
     * specific mapping is removed or repointed to a different connection.
     */
    public function forPairing(int $bazarrConnectionId, int $managingConnectionId): int
    {
        return $this->supersede(
            SubtitleCase::query()
                ->where('bazarr_connection_id', $bazarrConnectionId)
                ->where('service_connection_id', $managingConnectionId),
        );
    }

    /**
     * @param  Builder<SubtitleCase>  $query
     */
    private function supersede(Builder $query): int
    {
        $cases = $query
            ->whereIn('status', array_map(
                static fn (SubtitleCaseStatus $subtitleCaseStatus): string => $subtitleCaseStatus->value,
                self::ACTIVE_STATUSES,
            ))
            ->get();

        $superseded = 0;

        foreach ($cases as $case) {
            if ($this->subtitleCaseLifecycle->supersede($case)) {
                $superseded++;
            }
        }

        return $superseded;
    }
}
