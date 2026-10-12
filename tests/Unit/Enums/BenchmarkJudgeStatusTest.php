<?php

declare(strict_types=1);

use App\Enums\BenchmarkJudgeStatus;

test('every judge status has its stored value and label', function (BenchmarkJudgeStatus $benchmarkJudgeStatus, string $value, string $label): void {
    expect($benchmarkJudgeStatus->value)->toBe($value)
        ->and($benchmarkJudgeStatus->label())->toBe($label);
})->with([
    'none' => [BenchmarkJudgeStatus::None, 'none', 'Not judged'],
    'ok' => [BenchmarkJudgeStatus::Ok, 'ok', 'Judged'],
    'failed' => [BenchmarkJudgeStatus::Failed, 'failed', 'Judge failed'],
    'queued' => [BenchmarkJudgeStatus::Queued, 'queued', 'Queued for re-judging'],
    'pending' => [BenchmarkJudgeStatus::Pending, 'pending', 'Re-judging'],
]);

test('only queued and pending are the transient re-judging states', function (): void {
    $rejudging = array_values(array_filter(
        BenchmarkJudgeStatus::cases(),
        static fn (BenchmarkJudgeStatus $benchmarkJudgeStatus): bool => $benchmarkJudgeStatus->isRejudging(),
    ));

    expect($rejudging)->toBe([BenchmarkJudgeStatus::Queued, BenchmarkJudgeStatus::Pending]);
});
