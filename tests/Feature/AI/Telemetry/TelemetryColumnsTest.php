<?php

declare(strict_types=1);

use App\Enums\AiUsageKind;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;

test('usage records default to the text kind and accept telemetry fields', function (): void {
    $record = AiUsageRecord::factory()->create();

    expect($record->fresh()->kind)->toBe(AiUsageKind::Text);

    $failed = AiUsageRecord::factory()->failed()->reranking()->create(['parent_invocation_id' => 'parent-1', 'search_units' => 2]);

    expect($failed->fresh())
        ->kind->toBe(AiUsageKind::Reranking)
        ->status->toBe('failed')
        ->error_message->not->toBeNull()
        ->parent_invocation_id->toBe('parent-1');
});

test('tool invocations store duration and error code', function (): void {
    $invocation = AiToolInvocation::factory()->create(['duration_ms' => 42, 'error_code' => 'tool_failed', 'status' => 'failed']);

    expect($invocation->fresh())->duration_ms->toBe(42)->error_code->toBe('tool_failed');
});
