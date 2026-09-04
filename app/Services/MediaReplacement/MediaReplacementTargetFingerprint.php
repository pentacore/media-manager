<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

/**
 * Server-computed identity for "this exact installed file on this exact
 * connection". Computed at inspect time, echoed by the dialog, and recomputed
 * from a fresh inspection at replace time — a mismatch means the file changed
 * under the open dialog. The client never interprets it, so tampering can only
 * cause a rejection. Freshness semantics deliberately match the executor's
 * revalidation rule: equality of the current file-id set.
 */
final readonly class MediaReplacementTargetFingerprint
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshot(array $snapshot): string
    {
        $fileIds = array_map(
            intval(...),
            array_values((array) ($snapshot['episode_file_ids'] ?? $snapshot['movie_file_ids'] ?? [])),
        );
        sort($fileIds);

        return hash('sha256', json_encode([
            'service' => mb_strtolower(trim((string) ($snapshot['service'] ?? ''))),
            'connection' => (int) ($snapshot['service_connection_id'] ?? 0),
            'series_id' => isset($snapshot['series_id']) ? (int) $snapshot['series_id'] : null,
            'movie_id' => isset($snapshot['movie_id']) ? (int) $snapshot['movie_id'] : null,
            'season' => isset($snapshot['season_number']) ? (int) $snapshot['season_number'] : null,
            'episodes' => array_map(intval(...), array_values((array) ($snapshot['episode_numbers'] ?? []))),
            'file_ids' => $fileIds,
        ], JSON_THROW_ON_ERROR));
    }
}
