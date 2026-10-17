<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\DecisionAgentActed;

test('the notification names held and rejected second opinions', function (): void {
    $decisionAgentActed = new DecisionAgentActed('suggested', 2, 'Queued a monitor.', 'sonarr SeriesAdd', heldBySecondOpinion: 1, rejectedBySecondOpinion: 1);

    expect($decisionAgentActed->toArray(User::factory()->admin()->create())['message'])
        ->toBe("Queued a monitor.\nSecond opinion: 1 held, 1 rejected.");
});

test('the notification message is the summary when no second opinion intervened', function (): void {
    expect(new DecisionAgentActed('acted', 1, 'Monitored.')->toArray(User::factory()->admin()->create())['message'])->toBe('Monitored.');
});

test('the push body carries the second-opinion line', function (): void {
    $decisionAgentActed = new DecisionAgentActed('acted', 1, 'Monitored.', rejectedBySecondOpinion: 2);

    expect($decisionAgentActed->toPush(User::factory()->admin()->create())->body)
        ->toBe("Monitored.\nSecond opinion: 0 held, 2 rejected.");
});
