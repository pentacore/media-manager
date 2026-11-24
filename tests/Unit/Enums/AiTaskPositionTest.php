<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkRunStatus;

test('a task sorts in enum order and an unknown one sorts last', function (): void {
    $tasks = ['unknown', AiTask::Title->value, AiTask::Chat->value];

    usort($tasks, static fn (string $a, string $b): int => AiTask::position($a) <=> AiTask::position($b));

    expect(AiTask::position(AiTask::cases()[0]->value))->toBe(0)
        ->and(AiTask::position('unknown'))->toBe(PHP_INT_MAX)
        ->and(end($tasks))->toBe('unknown');
});

test('finished results and outright comparable runs are named once', function (): void {
    expect(BenchmarkResultStatus::finished())->toBe([BenchmarkResultStatus::Ok, BenchmarkResultStatus::Failed])
        ->and(BenchmarkRunStatus::comparable())->toBe([BenchmarkRunStatus::Completed, BenchmarkRunStatus::StoppedCostCap]);
});
