<?php

declare(strict_types=1);

use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\User;

/*
 * Characterisation coverage for resources/js/pages/Admin/AiUsage/Index.vue,
 * written before Batch 3c moved its sections into
 * resources/js/components/ai-usage/. Failed runs, tool stats, the kind
 * filter, drill-down tool errors, sub-agent runs and input/output text are
 * pinned by AiUsageTelemetryTest; the rate-limit card by
 * AiRateLimitEnforcementTest; the unpriced-model banner by
 * AiUnpricedModelWarningTest.
 */
beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);

    $this->actingAs(User::factory()->admin()->create());
});

test('the stat cards and breakdown tables summarise the window', function (): void {
    AiUsageRecord::factory()->count(2)->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'tool_calls_count' => 1]);
    AiUsageRecord::factory()->create(['provider' => 'anthropic', 'model' => 'claude-haiku-6']);

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-usage-stats]', 'Spend')
        ->assertSeeIn('[data-usage-stats]', '7d window')
        ->assertSeeIn('[data-usage-stats] > div:nth-child(2)', 'Invocations')
        ->assertSeeIn('[data-usage-stats]', '2 tool calls')
        ->assertSeeIn('[data-usage-stats]', 'prompt + completion')
        ->assertSeeIn('[data-usage-stats]', 'across all invocations')
        ->assertSeeIn('[data-usage-by-model]', 'By model')
        ->assertSeeIn('[data-usage-by-model]', 'gpt-5-mini')
        ->assertSeeIn('[data-usage-by-model]', 'claude-haiku-6')
        ->assertSeeIn('[data-usage-by-provider]', 'By provider')
        ->assertSeeIn('[data-usage-by-provider]', 'openai')
        ->assertSeeIn('[data-usage-by-provider]', 'anthropic')
        ->assertDontSeeIn('[data-usage-by-model]', 'Projected')
        ->assertSeeIn('[data-usage-ledger]', 'Recent invocations')
        ->assertSeeIn('[data-usage-ledger] tbody tr:nth-child(1)', 'System')
        ->assertAttributeContains('[data-usage-export]', 'href', 'window=7d')
        ->assertMissing('[data-usage-free-pools]')
        ->assertMissing('[data-usage-rate-limits]');
});

test('an empty window says so in every table', function (): void {
    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-usage-by-model]', 'No data in this window.')
        ->assertSeeIn('[data-usage-by-provider]', 'No data in this window.')
        ->assertSeeIn('[data-tool-stats]', 'No tool calls in this window.')
        ->assertSeeIn('[data-usage-ledger]', 'No invocations in this window.');
});

test('the free pool card shows each pool with its quota bars', function (): void {
    $splitPool = AiFreeUsagePool::factory()->create([
        'name' => 'Gemini free tier',
        'documentation_url' => 'https://ai.google.dev/pricing',
    ]);
    $unifiedPool = AiFreeUsagePool::factory()->unified(2_000_000)->create(['name' => 'Shared tier']);
    $splitRow = sprintf('[data-usage-free-pool="%d"]', $splitPool->id);
    $unifiedRow = sprintf('[data-usage-free-pool="%d"]', $unifiedPool->id);

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-usage-free-pools]', "Spend above subtracts what's still under quota.")
        ->assertSeeIn($splitRow, 'Gemini free tier')
        ->assertSeeIn($splitRow, 'resets monthly')
        ->assertSeeIn($splitRow, 'no usage yet')
        ->assertSeeIn($splitRow, 'Input')
        ->assertSeeIn($splitRow, '0 / 1,000,000')
        ->assertSeeIn($splitRow, 'Output')
        ->assertSeeIn($splitRow, '0 / 500,000')
        ->assertAttribute($splitRow.' a', 'href', 'https://ai.google.dev/pricing')
        ->assertSeeIn($unifiedRow, 'Tokens')
        ->assertSeeIn($unifiedRow, '0 / 2,000,000')
        ->assertMissing($unifiedRow.' a');
});

test('the what-if scenario loads rates from a priced model, projects costs and clears', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'input_per_mtok' => 1.00, 'output_per_mtok' => 2.00]);
    AiUsageRecord::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini']);

    $webpage = visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-usage-scenario]', 'What-if scenario')
        ->assertMissing('#rate_input')
        ->click('[data-usage-scenario-toggle]')
        ->click('[data-usage-scenario-load]')
        ->click('[role="option"]:has-text("openai / gpt-5-mini")')
        ->assertValue('#rate_input', '1')
        ->assertValue('#rate_output', '2')
        ->fill('#rate_output', '4')
        ->click('[data-usage-scenario-apply]')
        ->assertSeeIn('[data-usage-scenario]', 'active')
        ->assertScript("new URL(window.location.href).searchParams.get('scenario[input]')", '1')
        ->assertScript("new URL(window.location.href).searchParams.get('scenario[output]')", '4')
        ->assertSeeIn('[data-usage-stats]', 'projected')
        ->assertSeeIn('[data-usage-by-model]', 'Projected')
        ->assertSeeIn('[data-usage-by-provider]', 'Projected')
        ->assertSeeIn('[data-usage-ledger]', 'Projected')
        ->click('[data-usage-scenario-clear]')
        ->assertMissing('[data-usage-scenario-clear]');

    $webpage->assertScript("new URL(window.location.href).searchParams.has('scenario[input]')", false)
        ->assertDontSeeIn('[data-usage-by-model]', 'Projected');
});

test('the window filter reloads the page for the picked window', function (): void {
    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-usage-stats]', '7d window')
        ->click('30d')
        ->assertSeeIn('[data-usage-stats]', '30d window')
        ->assertQueryStringHas('window', '30d')
        ->assertAttributeContains('[data-usage-export]', 'href', 'window=30d');
});

test('the drill-down prices an unpriced call from the catalog', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'input_per_mtok' => 1.00, 'output_per_mtok' => 2.00]);
    $aiUsageRecord = AiUsageRecord::factory()->create(['provider' => 'openai', 'model' => 'mystery-model']);

    expect($aiUsageRecord->fresh()->input_per_mtok)->toBeNull();

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-usage-row="%d"]', $aiUsageRecord->id))
        ->assertSeeIn('[data-usage-detail]', 'Invocation detail')
        ->assertSeeIn('[data-usage-pricing]', 'Unpriced')
        ->assertSeeIn('[data-usage-pricing]', 'No price available')
        ->assertSeeIn('[data-usage-breakdown]', 'Cache write')
        ->assertSeeIn('[data-usage-breakdown]', 'Total')
        ->assertSeeIn('[data-usage-assign]', 'Assign price from catalog')
        ->assertScript("document.querySelector('[data-usage-assign-submit]').disabled", true)
        ->click('[data-usage-assign-select]')
        ->click('[role="option"]:has-text("openai / gpt-5-mini")')
        ->click('[data-usage-assign-submit]')
        ->assertSee('Pricing assigned from openai/gpt-5-mini.')
        ->assertSeeIn('[data-usage-pricing]', '(retroactively assigned)')
        ->assertSeeIn('[data-usage-pricing]', 'Snapshot at call time')
        ->assertMissing('[data-usage-assign]')
        ->click('[data-usage-detail-close]')
        ->assertMissing('[data-usage-detail]');

    expect($aiUsageRecord->fresh()->price_source)->toBe('assigned');
});

test('a drill-down whose record has gone shows the load error', function (): void {
    $aiUsageRecord = AiUsageRecord::factory()->create();

    $webpage = visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertPresent(sprintf('[data-usage-row="%d"]', $aiUsageRecord->id));

    $aiUsageRecord->delete();

    $webpage->click(sprintf('[data-usage-row="%d"]', $aiUsageRecord->id))
        ->assertSeeIn('[data-usage-detail]', 'HTTP 404');
});

test('with a scenario active the drill-down shows the scenario column', function (): void {
    $aiUsageRecord = AiUsageRecord::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini']);
    $path = route('admin.ai-usage.index', [
        'scenario' => ['input' => 1, 'output' => 2, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0],
    ], absolute: false);

    visit($path)
        ->assertNoSmoke()
        ->assertSeeIn('[data-usage-scenario]', 'active')
        ->click(sprintf('[data-usage-row="%d"]', $aiUsageRecord->id))
        ->assertSeeIn('[data-usage-breakdown]', 'Scenario');
});
