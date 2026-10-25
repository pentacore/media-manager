<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\ActionRequestStatus;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;

/**
 * Builds the chat card payload for a series subtitle audit batch.
 */
final class SubtitleAuditBatchCard
{
    public const string TYPE = 'subtitle_audit_batch';

    /**
     * @return array{type: string, id: int, series_id: int, series_title: string, status: string, episodes: list<array{label: string, season_number: int, episode_number: int, outcome: string, missing_languages: list<string>, release_title: string|null, action_request_id: int|null, action_status: string|null}>, pending_action_request_ids: list<int>}
     */
    public static function present(SubtitleAuditBatch $subtitleAuditBatch): array
    {
        $subtitleAuditBatch->load('episodes.actionRequest:id,status');

        $episodes = $subtitleAuditBatch->episodes
            ->sortBy([['season_number', 'asc'], ['episode_number', 'asc']])
            ->values();

        $pendingIds = [];
        $rows = [];

        foreach ($episodes as $episode) {
            $actionStatus = $episode->actionRequest?->status;

            if ($actionStatus === ActionRequestStatus::Pending && $episode->action_request_id !== null) {
                $pendingIds[] = $episode->action_request_id;
            }

            $rows[] = self::presentEpisode($episode, $actionStatus);
        }

        return [
            'type' => self::TYPE,
            'id' => $subtitleAuditBatch->id,
            'series_id' => $subtitleAuditBatch->series_id,
            'series_title' => $subtitleAuditBatch->series_title,
            'status' => $subtitleAuditBatch->status->value,
            'episodes' => $rows,
            'pending_action_request_ids' => $pendingIds,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public static function presentMany(array $ids): array
    {
        $cards = [];

        foreach (SubtitleAuditBatch::query()->whereIn('id', $ids)->get() as $subtitleAuditBatch) {
            $cards[$subtitleAuditBatch->id] = self::present($subtitleAuditBatch);
        }

        return $cards;
    }

    /**
     * @return array{label: string, season_number: int, episode_number: int, outcome: string, missing_languages: list<string>, release_title: string|null, action_request_id: int|null, action_status: string|null}
     */
    private static function presentEpisode(SubtitleAuditEpisode $subtitleAuditEpisode, ?ActionRequestStatus $actionStatus): array
    {
        return [
            'label' => $subtitleAuditEpisode->label(),
            'season_number' => $subtitleAuditEpisode->season_number,
            'episode_number' => $subtitleAuditEpisode->episode_number,
            'outcome' => $subtitleAuditEpisode->outcome->value,
            'missing_languages' => $subtitleAuditEpisode->missing_languages,
            'release_title' => $subtitleAuditEpisode->release_title,
            'action_request_id' => $subtitleAuditEpisode->action_request_id,
            'action_status' => $actionStatus?->value,
        ];
    }
}
