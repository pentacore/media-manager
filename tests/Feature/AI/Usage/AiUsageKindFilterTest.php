<?php

declare(strict_types=1);

use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Services\AiUsage\AiUsageReporting;

test('the usage page filters by kind and exposes tool stats', function (): void {
    AiUsageRecord::factory()->create();
    AiUsageRecord::factory()->embeddings()->create();
    AiToolInvocation::factory()->create(['duration_ms' => 100, 'status' => 'success']);
    AiToolInvocation::factory()->create(['duration_ms' => 300, 'status' => 'failed', 'error_code' => 'tool_failed']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-usage.index', ['kind' => 'embeddings']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/AiUsage/Index')
            ->where('kind', 'embeddings')
            ->has('kinds', 4)
            ->has('recent', 1)
            ->where('recent.0.kind', 'embeddings')
            ->where('totals.total_invocations', 1)
            ->where('tool_stats.0.calls', 2)
            ->where('tool_stats.0.failures', 1)
            ->where('tool_stats.0.p50_ms', 200)
            ->where('tool_stats.0.p95_ms', 290));
});

test('an invalid kind is ignored', function (): void {
    AiUsageRecord::factory()->count(2)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-usage.index', ['kind' => 'nope']))
        ->assertInertia(fn ($page) => $page->where('kind', null)->has('recent', 2));
});

test('recent rows carry the failure message', function (): void {
    AiUsageRecord::factory()->failed()->create(['error_message' => 'Provider returned 500.']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-usage.index'))
        ->assertInertia(fn ($page) => $page
            ->where('recent.0.status', 'failed')
            ->where('recent.0.kind', 'text')
            ->where('recent.0.error_message', 'Provider returned 500.'));
});

test('the invocation detail lists sub-agent runs and tool telemetry', function (): void {
    $parent = AiUsageRecord::factory()->create();
    $child = AiUsageRecord::factory()->create([
        'parent_invocation_id' => $parent->invocation_id,
        'prompt_tokens' => 100,
        'completion_tokens' => 20,
    ]);
    AiToolInvocation::factory()->create([
        'invocation_id' => $parent->invocation_id,
        'status' => 'failed',
        'error_code' => 'tool_failed',
        'duration_ms' => 42,
    ]);

    $detail = resolve(AiUsageReporting::class)->invocationDetail($parent->refresh());

    expect($detail['children'])->toHaveCount(1)
        ->and($detail['children'][0])->toMatchArray([
            'id' => $child->id,
            'model' => 'gpt-5-mini',
            'status' => 'success',
            'total_tokens' => 120,
        ])
        ->and($detail['tools'][0])->toMatchArray(['error_code' => 'tool_failed', 'duration_ms' => 42]);
});

test('the export honours the kind filter', function (): void {
    AiUsageRecord::factory()->create(['model' => 'text-model']);
    AiUsageRecord::factory()->embeddings()->create(['model' => 'embed-model']);

    $csv = $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-usage.export', ['kind' => 'embeddings']))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('embed-model')->not->toContain('text-model');
});
