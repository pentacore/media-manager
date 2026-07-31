<?php

declare(strict_types=1);

use App\Ai\SubtitleAdvisor\SubtitleAdvisorRunContext;

test('it records at most one queued action request', function (): void {
    $context = new SubtitleAdvisorRunContext(caseId: 42, maxActions: 1);

    $context->recordQueued(1001);

    expect($context->caseId)->toBe(42)
        ->and($context->actionRequestId())->toBe(1001)
        ->and($context->capReached())->toBeTrue()
        ->and(fn (): null => $context->recordQueued(1002))
        ->toThrow(LogicException::class, 'action cap');
});

test('separate contexts do not leak queued state', function (): void {
    $first = new SubtitleAdvisorRunContext(caseId: 42, maxActions: 1);
    $second = new SubtitleAdvisorRunContext(caseId: 43, maxActions: 1);

    $first->recordQueued(1001);

    expect($first->actionRequestId())->toBe(1001)
        ->and($second->actionRequestId())->toBeNull()
        ->and($second->capReached())->toBeFalse();
});
