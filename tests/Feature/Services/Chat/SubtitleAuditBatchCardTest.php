<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Models\ActionRequest;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;
use App\Services\Chat\SubtitleAuditBatchCard;
use Illuminate\Support\Facades\DB;

test('the card lists episodes in order with their action status and the pending ids', function (): void {
    $batch = SubtitleAuditBatch::factory()->create(['series_id' => 42, 'series_title' => 'Show']);
    $pending = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    $approved = ActionRequest::factory()->create(['status' => ActionRequestStatus::Approved]);

    SubtitleAuditEpisode::factory()->queued()->create([
        'subtitle_audit_batch_id' => $batch->id, 'season_number' => 2, 'episode_number' => 1,
        'action_request_id' => $approved->id, 'release_title' => 'Show.S02E01.CR',
    ]);
    SubtitleAuditEpisode::factory()->manualPick()->create([
        'subtitle_audit_batch_id' => $batch->id, 'season_number' => 1, 'episode_number' => 3,
    ]);
    SubtitleAuditEpisode::factory()->queued()->create([
        'subtitle_audit_batch_id' => $batch->id, 'season_number' => 1, 'episode_number' => 2,
        'action_request_id' => $pending->id, 'release_title' => 'Show.S01E02.CR',
    ]);

    $card = SubtitleAuditBatchCard::present($batch);

    expect($card['type'])->toBe('subtitle_audit_batch')
        ->and($card['id'])->toBe($batch->id)
        ->and($card['series_id'])->toBe(42)
        ->and($card['series_title'])->toBe('Show')
        ->and($card['status'])->toBe('searching')
        ->and(array_column($card['episodes'], 'label'))->toBe(['S01E02', 'S01E03', 'S02E01'])
        ->and($card['episodes'][0])->toBe([
            'label' => 'S01E02', 'season_number' => 1, 'episode_number' => 2,
            'outcome' => SubtitleAuditEpisodeOutcome::Queued->value, 'missing_languages' => ['eng'],
            'release_title' => 'Show.S01E02.CR', 'failure_reason' => null, 'action_request_id' => $pending->id, 'action_status' => 'pending',
        ])
        ->and($card['episodes'][1]['action_request_id'])->toBeNull()
        ->and($card['episodes'][1]['action_status'])->toBeNull()
        ->and($card['episodes'][2]['action_status'])->toBe('approved')
        ->and($card['pending_action_request_ids'])->toBe([$pending->id]);
});

test('presentMany skips deleted batches', function (): void {
    $batch = SubtitleAuditBatch::factory()->create();

    $cards = SubtitleAuditBatchCard::presentMany([$batch->id, $batch->id + 999]);

    expect(array_keys($cards))->toBe([$batch->id])
        ->and($cards[$batch->id]['type'])->toBe('subtitle_audit_batch');
});

test('presentMany loads every batch with a constant number of queries', function (): void {
    $batches = SubtitleAuditBatch::factory()->count(3)->create();

    foreach ($batches as $batch) {
        foreach ([1, 2] as $episodeNumber) {
            SubtitleAuditEpisode::factory()->queued()->create([
                'subtitle_audit_batch_id' => $batch->id, 'season_number' => 1, 'episode_number' => $episodeNumber,
                'action_request_id' => ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending])->id,
            ]);
        }
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $cards = SubtitleAuditBatchCard::presentMany($batches->modelKeys());

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($cards)->toHaveCount(3)
        ->and(array_map(fn (array $card): int => count($card['pending_action_request_ids']), $cards))->each->toBe(2)
        ->and($queries)->toHaveCount(3);
});
