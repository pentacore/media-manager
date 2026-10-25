<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;
use App\Services\Chat\ChatCardExtractor;
use App\Services\Chat\SubtitleAuditBatchCard;

/**
 * @return list<array{id: string, name: string, result: mixed}>
 */
function mixedToolResults(int $actionRequestId): array
{
    return [
        ['id' => 'call_1', 'name' => 'OfferChoicesTool', 'result' => json_encode([
            'choices' => ['question' => 'Which?', 'multiple' => true, 'options' => [['label' => 'A', 'description' => null], ['label' => 'B', 'description' => 'bee']]],
            'instruction' => 'x',
        ])],
        ['id' => 'call_2', 'name' => 'GetMediaTool', 'result' => json_encode(['items' => []])],
        ['id' => 'call_3', 'name' => 'GetMediaTool', 'result' => 'not json at all'],
        ['id' => 'call_4', 'name' => 'DeleteMediaTool', 'result' => json_encode(['queued' => true, 'action_request_id' => $actionRequestId, 'status' => 'pending', 'requires_approval' => true, 'title' => 'Replace X'])],
        ['id' => 'call_5', 'name' => 'SomeOtherTool', 'result' => json_encode(['choices' => ['question' => 'nope', 'options' => []]])],
    ];
}

test('extracts choices and action request cards in order', function (): void {
    $cards = resolve(ChatCardExtractor::class)->fromToolResults(mixedToolResults(5));

    expect($cards)->toBe([
        ['type' => 'choices', 'id' => 'call_1', 'question' => 'Which?', 'multiple' => true, 'options' => [['label' => 'A', 'description' => null], ['label' => 'B', 'description' => 'bee']]],
        ['type' => 'action_request', 'id' => 5, 'title' => 'Replace X', 'status' => 'pending', 'requires_approval' => true],
    ]);
});

test('hydrate takes the live status and drops deleted rows', function (): void {
    $live = ActionRequest::factory()->create(['title' => 'Live title', 'status' => ActionRequestStatus::Approved]);
    $chatCardExtractor = resolve(ChatCardExtractor::class);

    $cards = $chatCardExtractor->hydrate($chatCardExtractor->fromToolResults(mixedToolResults($live->id)));

    expect($cards)->toHaveCount(2)
        ->and($cards[1]['status'])->toBe(ActionRequestStatus::Approved->value)
        ->and($cards[1]['title'])->toBe('Live title');

    $live->delete();

    expect($chatCardExtractor->hydrate($chatCardExtractor->fromToolResults(mixedToolResults($live->id))))->toHaveCount(1);
});

test('skips choice options that are not objects', function (): void {
    $cards = resolve(ChatCardExtractor::class)->fromToolResults([
        ['id' => 'call_1', 'name' => 'OfferChoicesTool', 'result' => json_encode([
            'choices' => ['question' => 'Which?', 'options' => ['loose string', ['label' => 'A'], null, 7]],
        ])],
    ]);

    expect($cards[0]['options'])->toBe([['label' => 'A', 'description' => null]]);
});

test('reads stored tool calls and skips one still awaiting approval', function (): void {
    $cards = resolve(ChatCardExtractor::class)->fromToolResults([
        ['id' => 'call_1', 'name' => 'DeleteMediaTool', 'arguments' => [], 'result_id' => null, 'approval_reason' => 'Needs approval'],
        ['id' => 'call_2', 'name' => 'OfferChoicesTool', 'arguments' => [], 'result_id' => null, 'result' => json_encode([
            'choices' => ['question' => 'Which?', 'options' => [['label' => 'A']]],
        ])],
    ]);

    expect($cards)->toBe([
        ['type' => 'choices', 'id' => 'call_2', 'question' => 'Which?', 'multiple' => false, 'options' => [['label' => 'A', 'description' => null]]],
    ]);
});

test('a tool request result becomes a tool_request card', function (): void {
    $result = ['tool_request' => ['groups' => [['group' => 'subtitles_replacement', 'label' => 'Subtitles & replacement']], 'reason' => 'Needs the file'], 'instruction' => 'x'];
    $chatCardExtractor = resolve(ChatCardExtractor::class);
    $expected = [['type' => 'tool_request', 'id' => 'call_9', 'groups' => [['group' => 'subtitles_replacement', 'label' => 'Subtitles & replacement']], 'reason' => 'Needs the file']];

    $live = $chatCardExtractor->fromToolResults([['id' => 'call_9', 'name' => 'RequestToolsTool', 'result' => $result]]);
    $stored = $chatCardExtractor->fromToolResults([['id' => 'call_9', 'name' => 'RequestToolsTool', 'result' => json_encode($result)]]);

    expect($live)->toBe($expected)
        ->and($stored)->toBe($expected)
        ->and($chatCardExtractor->hydrate($stored))->toBe($expected);
});

test('a refused tool request yields no card', function (): void {
    $cards = resolve(ChatCardExtractor::class)->fromToolResults([['id' => 'c', 'name' => 'RequestToolsTool', 'result' => json_encode(['error' => 'already_loaded'])]]);

    expect($cards)->toBe([]);
});

test('a repair result becomes a subtitle audit batch card and hydrates its statuses', function (): void {
    $batch = SubtitleAuditBatch::factory()->ready()->create(['series_id' => 42, 'series_title' => 'Show']);
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    SubtitleAuditEpisode::factory()->queued()->create([
        'subtitle_audit_batch_id' => $batch->id, 'season_number' => 1, 'episode_number' => 2,
        'action_request_id' => $actionRequest->id,
    ]);
    SubtitleAuditEpisode::factory()->manualPick()->create([
        'subtitle_audit_batch_id' => $batch->id, 'season_number' => 1, 'episode_number' => 3,
    ]);
    $stale = SubtitleAuditBatchCard::present($batch);
    $toolCalls = [
        ['id' => 'call_1', 'name' => 'RepairSeriesSubtitlesTool', 'arguments' => [], 'result_id' => null, 'result' => json_encode(['subtitle_audit_batch' => $stale])],
        ['id' => 'call_2', 'name' => 'RepairSeriesSubtitlesTool', 'arguments' => [], 'result_id' => null, 'result' => json_encode(['handed_off' => true, 'subtitle_audit_batch_id' => $batch->id])],
        ['id' => 'call_3', 'name' => 'GetMediaTool', 'arguments' => [], 'result_id' => null, 'result' => json_encode(['subtitle_audit_batch' => ['type' => 'other', 'id' => 1]])],
        ['id' => 'call_4', 'name' => 'GetMediaTool', 'arguments' => [], 'result_id' => null, 'result' => json_encode(['subtitle_audit_batch' => ['type' => 'subtitle_audit_batch', 'id' => '7']])],
    ];
    $chatCardExtractor = resolve(ChatCardExtractor::class);

    $cards = $chatCardExtractor->fromToolResults($toolCalls);

    expect($cards)->toBe([$stale])
        ->and($cards[0]['episodes'][0]['action_status'])->toBe('pending')
        ->and($cards[0]['pending_action_request_ids'])->toBe([$actionRequest->id]);

    $actionRequest->update(['status' => ActionRequestStatus::Approved]);

    $hydrated = $chatCardExtractor->hydrate($cards);

    expect($hydrated)->toHaveCount(1)
        ->and($hydrated[0]['type'])->toBe('subtitle_audit_batch')
        ->and($hydrated[0]['episodes'][0]['action_status'])->toBe('approved')
        ->and($hydrated[0]['episodes'][1]['outcome'])->toBe('manual_pick')
        ->and($hydrated[0]['pending_action_request_ids'])->toBe([]);

    $batch->delete();

    expect($chatCardExtractor->hydrate($cards))->toBe([]);
});

test('a handed off repair shows its searching card, which hydrates to the finished batch', function (): void {
    $batch = SubtitleAuditBatch::factory()->create(['series_id' => 42, 'series_title' => 'Show', 'handed_off_at' => now()]);
    $episode = SubtitleAuditEpisode::factory()->create([
        'subtitle_audit_batch_id' => $batch->id, 'season_number' => 1, 'episode_number' => 2,
    ]);
    $chatCardExtractor = resolve(ChatCardExtractor::class);

    $cards = $chatCardExtractor->fromToolResults([[
        'id' => 'call_1', 'name' => 'RepairSeriesSubtitlesTool', 'arguments' => [], 'result_id' => null,
        'result' => json_encode(['handed_off' => true, 'subtitle_audit_batch_id' => $batch->id, 'searching' => 1, 'message' => 'Later.', 'subtitle_audit_batch' => SubtitleAuditBatchCard::present($batch)]),
    ]]);

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['status'])->toBe('searching')
        ->and($cards[0]['episodes'][0]['outcome'])->toBe('searching');

    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);
    $episode->update(['outcome' => 'queued', 'action_request_id' => $actionRequest->id, 'release_title' => 'Show.S01E02.CR']);
    $batch->update(['status' => 'ready', 'completed_at' => now()]);

    $hydrated = $chatCardExtractor->hydrate($cards);

    expect($hydrated[0]['status'])->toBe('ready')
        ->and($hydrated[0]['episodes'][0]['outcome'])->toBe('queued')
        ->and($hydrated[0]['pending_action_request_ids'])->toBe([$actionRequest->id]);
});

test('hydrating several messages keeps each card in its group and order', function (): void {
    $first = SubtitleAuditBatch::factory()->create(['series_title' => 'First']);
    $second = SubtitleAuditBatch::factory()->create(['series_title' => 'Second']);
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Rejected]);
    $chatCardExtractor = resolve(ChatCardExtractor::class);
    $actionCard = ['type' => 'action_request', 'id' => $actionRequest->id, 'title' => null, 'status' => 'pending', 'requires_approval' => true];

    $groups = $chatCardExtractor->hydrateGroups([
        [SubtitleAuditBatchCard::present($first), $actionCard],
        [],
        [SubtitleAuditBatchCard::present($second)],
    ]);

    expect(array_map(fn (array $cards): array => array_column($cards, 'type'), $groups))->toBe([
        ['subtitle_audit_batch', 'action_request'],
        [],
        ['subtitle_audit_batch'],
    ])
        ->and($groups[0][0]['series_title'])->toBe('First')
        ->and($groups[0][1]['status'])->toBe('rejected')
        ->and($groups[2][0]['series_title'])->toBe('Second');
});
