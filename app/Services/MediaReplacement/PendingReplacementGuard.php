<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\ActionRequestStatus;
use App\Enums\MediaReplacementStatus;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;

/**
 * Queue-time duplicate check for manual replacements: one in-flight
 * replacement per installed target. Execution-time safety is the shared
 * media-target lock; this guard exists so a member gets a clear refusal at
 * submit time instead of a queued request that will collide later.
 */
final readonly class PendingReplacementGuard
{
    /**
     * @param  array<string, mixed>  $target
     */
    public function inFlightFor(array $target): bool
    {
        $connectionId = (int) ($target['service_connection_id'] ?? 0);
        $movieId = isset($target['movie_id']) ? (int) $target['movie_id'] : null;
        $seriesId = isset($target['series_id']) ? (int) $target['series_id'] : null;

        // terminalValues() is the centralized terminal-state definition — do
        // not duplicate the list here.
        $attempt = MediaReplacementAttempt::query()
            ->whereNotIn('status', MediaReplacementStatus::terminalValues())
            ->where('target->service_connection_id', $connectionId)
            ->when($movieId !== null, fn ($query) => $query->where('target->movie_id', $movieId))
            ->when($seriesId !== null, fn ($query) => $query
                ->where('target->series_id', $seriesId)
                ->where('target->season_number', (int) ($target['season_number'] ?? 0))
                ->where('target->episode_numbers', json_encode(array_values((array) ($target['episode_numbers'] ?? [])))))
            ->exists();

        if ($attempt) {
            return true;
        }

        return ActionRequest::query()
            ->where('type', 'replace_media_file')
            ->whereIn('status', [ActionRequestStatus::Pending, ActionRequestStatus::Approved, ActionRequestStatus::Executing])
            ->where('payload->target->service_connection_id', $connectionId)
            ->when($movieId !== null, fn ($query) => $query->where('payload->target->movie_id', $movieId))
            ->when($seriesId !== null, fn ($query) => $query
                ->where('payload->target->series_id', $seriesId)
                ->where('payload->target->season_number', (int) ($target['season_number'] ?? 0))
                // Episode identity too — otherwise one queued replacement blocks
                // every other episode in the same season.
                ->where('payload->target->episode_numbers', json_encode(array_values((array) ($target['episode_numbers'] ?? [])))))
            ->exists();
    }
}
