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
    $extractor = resolve(ChatCardExtractor::class);

    $cards = $extractor->hydrate($extractor->fromToolResults(mixedToolResults($live->id)));

    expect($cards)->toHaveCount(2)
        ->and($cards[1]['status'])->toBe(ActionRequestStatus::Approved->value)
        ->and($cards[1]['title'])->toBe('Live title');

    $live->delete();

    expect($extractor->hydrate($extractor->fromToolResults(mixedToolResults($live->id))))->toHaveCount(1);
});
