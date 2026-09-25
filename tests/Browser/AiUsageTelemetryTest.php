<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\User;

test('admin sees failed runs, tool stats and can filter by kind', function (): void {
    AiUsageRecord::factory()->failed()->create(['error_message' => 'Provider returned 500.']);
    AiUsageRecord::factory()->embeddings()->create(['model' => 'text-embedding-3-small']);
    AiToolInvocation::factory()->create(['duration_ms' => 250, 'status' => 'failed', 'error_code' => 'tool_failed']);

    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-usage-error]', 'Provider returned 500.')
        ->assertVisible('[data-tool-stats]')
        ->assertSeeIn('[data-tool-stat-row]', 'SearchMediaTool')
        ->assertSeeIn('[data-tool-stat-row]', '100%')
        ->assertSeeIn('[data-usage-kind]', 'Embeddings')
        ->click('#usage-kind')
        ->click('[role="option"][aria-label="Embeddings"]')
        ->assertQueryStringHas('kind', 'embeddings')
        ->assertSee('text-embedding-3-small')
        ->assertDontSee('Provider returned 500.');
});

test('the invocation drill-down lists sub-agent runs and tool errors', function (): void {
    $parent = AiUsageRecord::factory()->create(['model' => 'parent-model']);
    AiUsageRecord::factory()->create([
        'parent_invocation_id' => $parent->invocation_id,
        'agent_class' => MediaAgent::class,
        'model' => 'child-model',
    ]);
    AiToolInvocation::factory()->create([
        'invocation_id' => $parent->invocation_id,
        'status' => 'failed',
        'error_code' => 'tool_failed',
        'duration_ms' => 42,
    ]);

    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-usage-row="%d"]', $parent->id))
        ->assertSeeIn('[data-usage-children]', 'child-model')
        ->assertSeeIn('[data-usage-tool-error]', 'tool_failed');
});
