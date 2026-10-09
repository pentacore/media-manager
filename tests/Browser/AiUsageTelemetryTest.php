<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\AiModelPrice;
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

test('the invocation drill-down shows the input and output text', function (): void {
    $record = AiUsageRecord::factory()->create([
        'prompt_text' => 'Find severance in Sonarr.',
        'response_text' => 'Found 3 series matching "severance".',
    ]);

    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-usage-row="%d"]', $record->id))
        ->assertSeeIn('[data-usage-io="input"]', 'Find severance in Sonarr.')
        ->assertSeeIn('[data-usage-io="output"]', 'Found 3 series matching "severance".');
});

test('the drill-down of a failed run shows its input without an output block', function (): void {
    $record = AiUsageRecord::factory()->failed()->create(['prompt_text' => 'Find severance in Sonarr.']);

    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-usage-row="%d"]', $record->id))
        ->assertSeeIn('[data-usage-io="input"]', 'Find severance in Sonarr.')
        ->assertMissing('[data-usage-io="output"]');
});

test('admin sees which runs fell through a tier and can filter them', function (): void {
    $tierTwo = AiUsageRecord::factory()->create(['model' => 'gpt-tier-two', 'tier_position' => 2]);
    $noTier = AiUsageRecord::factory()->create(['model' => 'gpt-no-tier', 'tier_position' => null]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-usage-tier]', 'Tier 2')
        ->click('[data-usage-tier-filter] button')
        ->click('[role="option"][aria-label="Fell through"]')
        ->assertQueryStringHas('tier', 'fell_through')
        ->assertSee('Tier filter applies to this list')
        ->assertPresent("[data-usage-row=\"{$tierTwo->id}\"]")
        ->assertMissing("[data-usage-row=\"{$noTier->id}\"]");
});

test('the tier filter stays in the query string across a window, kind and scenario change', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'input_per_mtok' => 1.00, 'output_per_mtok' => 2.00]);
    AiUsageRecord::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'tier_position' => 2]);
    AiUsageRecord::factory()->embeddings()->create(['model' => 'text-embedding-3-small', 'tier_position' => 2]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-usage-tier-filter] button')
        ->click('[role="option"][aria-label="Fell through"]')
        ->assertQueryStringHas('tier', 'fell_through')
        ->click('30d')
        ->assertQueryStringHas('tier', 'fell_through')
        ->click('#usage-kind')
        ->click('[role="option"][aria-label="Embeddings"]')
        ->assertQueryStringHas('tier', 'fell_through')
        ->click('[data-usage-scenario-toggle]')
        ->click('[data-usage-scenario-load]')
        ->click('[role="option"]:has-text("openai / gpt-5-mini")')
        ->click('[data-usage-scenario-apply]')
        ->assertQueryStringHas('tier', 'fell_through');
});
