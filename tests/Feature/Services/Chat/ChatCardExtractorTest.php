<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Services\Chat\ChatCardExtractor;

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
