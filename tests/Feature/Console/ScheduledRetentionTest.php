<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\AiPriceRefreshRun;
use App\Models\MediaReplacementAttempt;
use App\Models\SubtitleCase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function scheduledRetentionCommand(string $needle): ?Event
{
    return collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, $needle));
}

test('the nightly model prune covers the new tables and frees subtitle cases before their action requests', function (): void {
    $command = (string) scheduledRetentionCommand('model:prune')?->command;

    expect($command)->toContain(AiPriceRefreshRun::class)
        ->and(mb_strpos($command, SubtitleCase::class))->toBeLessThan(mb_strpos($command, ActionRequest::class))
        ->and(mb_strpos($command, MediaReplacementAttempt::class))->toBeLessThan(mb_strpos($command, ActionRequest::class));
});

test('failed jobs and job batches are pruned nightly on their retention windows', function (): void {
    $failedJobs = scheduledRetentionCommand('queue:prune-failed');
    $batches = scheduledRetentionCommand('queue:prune-batches');

    expect($failedJobs)->not->toBeNull()
        ->and((string) $failedJobs->command)->toContain('--hours=720')
        ->and($batches)->not->toBeNull()
        ->and((string) $batches->command)->toContain('--hours=168')->toContain('--unfinished=168')->toContain('--cancelled=168');
});
