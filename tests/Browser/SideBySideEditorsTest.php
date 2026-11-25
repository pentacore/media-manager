<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\AiTask;
use App\Enums\AiUsageKind;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkCase;
use App\Models\ChatTemplate;
use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

/**
 * @return array<string, array{left: float, right: float, top: float, bottom: float, width: float}>
 */
function sideBySideRects(mixed $webpage, string ...$selectors): array
{
    $list = json_encode($selectors);

    return $webpage->script(<<<JS
        (() => {
            const out = {};
            for (const selector of {$list}) {
                const r = document.querySelector(selector).getBoundingClientRect();
                out[selector] = { left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width };
            }
            return out;
        })()
    JS);
}

function sideBySideTemplate(string $body = 'Check {{anime}} S{{season}}'): ChatTemplate
{
    $admin = User::factory()->admin()->create();
    test()->actingAs($admin);

    return ChatTemplate::factory()->for($admin)->subtitleCheck()->create(['body' => $body]);
}

test('shows the template preview beside the message from 1280 px', function (): void {
    $chatTemplate = sideBySideTemplate();

    $webpage = visit(route('ai.templates.edit', $chatTemplate, absolute: false))
        ->resize(1440, 900)
        ->assertVisible('[data-template-preview-section]');

    $rects = sideBySideRects($webpage, '[data-template-body]', '[data-template-preview-section]');

    expect($rects['[data-template-preview-section]']['left'])->toBeGreaterThan($rects['[data-template-body]']['right'])
        ->and($rects['[data-template-body]']['top'])->toBeLessThan(900)
        ->and($rects['[data-template-preview-section]']['top'])->toBeLessThan(900);
});

test('keeps the template preview stuck below the header while scrolling a tall editor', function (): void {
    $chatTemplate = sideBySideTemplate();

    $webpage = visit(route('ai.templates.edit', $chatTemplate, absolute: false))
        ->resize(1440, 900)
        ->fill('[data-template-body]', sideBySideLongBody())
        ->assertVisible('[data-variable-row="v20"]');

    $before = $webpage->script("document.querySelector('[data-template-preview-section]').getBoundingClientRect().top");

    $webpage->script('window.scrollTo(0, document.documentElement.scrollHeight)');
    $webpage->script('new Promise((resolve) => setTimeout(() => requestAnimationFrame(() => setTimeout(resolve, 0)), 0))');

    $after = $webpage->script("document.querySelector('[data-template-preview-section]').getBoundingClientRect().top");

    expect($webpage->script('window.scrollY'))->toBeGreaterThan(300)
        ->and($before)->toBeGreaterThan(68)
        ->and(abs($after - 68))->toBeLessThan(2);
});

test('scrolls a preview taller than the viewport inside its own box', function (): void {
    $chatTemplate = sideBySideTemplate();

    $webpage = visit(route('ai.templates.edit', $chatTemplate, absolute: false))
        ->resize(1440, 900)
        ->fill('[data-template-body]', sideBySideLongBody())
        ->assertVisible('[data-variable-row="v20"]');

    $webpage->script("new Promise((resolve) => { const poll = () => { const el = document.querySelector('[data-template-preview]'); el.scrollHeight > el.clientHeight ? resolve(true) : setTimeout(poll, 100); }; poll(); })");
    $webpage->script('window.scrollTo(0, document.documentElement.scrollHeight)');

    $metrics = $webpage->script(<<<'JS'
        (() => {
            const box = document.querySelector('[data-template-preview]');
            const section = document.querySelector('[data-template-preview-section]');

            return {
                scroll: box.scrollHeight,
                client: box.clientHeight,
                overflow: getComputedStyle(box).overflowY,
                tabindex: box.getAttribute('tabindex'),
                label: box.getAttribute('aria-label'),
                sectionBottom: section.getBoundingClientRect().bottom,
                inner: window.innerHeight,
            };
        })()
    JS);

    expect($metrics['scroll'])->toBeGreaterThan($metrics['client'])
        ->and($metrics['sectionBottom'])->toBeLessThanOrEqual($metrics['inner'])
        ->and($metrics['overflow'])->toBe('auto')
        ->and($metrics['tabindex'])->toBe('0')
        ->and($metrics['label'])->not->toBeEmpty();
});

test('does not stretch the left blocks when the preview is taller than they are', function (): void {
    $chatTemplate = sideBySideTemplate('Short plain message');

    $webpage = visit(route('ai.templates.edit', $chatTemplate, absolute: false))->resize(1440, 900);

    $measure = <<<'JS'
        (() => {
            const label = document.querySelector('[data-template-name]').closest('label');
            const message = document.querySelector('[data-template-body]').closest('label').parentElement;
            const toggles = document.querySelector('[data-template-auto-send]').parentElement;

            return {
                nameLabel: label.getBoundingClientRect().height,
                messageToToggles: toggles.getBoundingClientRect().top - message.getBoundingClientRect().bottom,
            };
        })()
    JS;

    $short = $webpage->script($measure);

    $webpage->fill('[data-template-body]', implode("\n", array_map(fn (int $i): string => "Plain line {$i}", range(1, 70))));
    $webpage->script("new Promise((resolve) => { const poll = () => document.querySelector('[data-template-preview]').scrollHeight > 800 ? resolve(true) : setTimeout(poll, 100); poll(); })");

    $tall = $webpage->script($measure);

    expect(abs($tall['nameLabel'] - $short['nameLabel']))->toBeLessThanOrEqual(1)
        ->and(abs($tall['messageToToggles'] - 24))->toBeLessThanOrEqual(2);
});

test('shows preview errors in the right column', function (): void {
    $chatTemplate = sideBySideTemplate();

    $webpage = visit(route('ai.templates.edit', $chatTemplate, absolute: false))
        ->resize(1440, 900)
        ->fill('[data-template-body]', 'Check {{anime:poster}}')
        ->assertVisible('[data-template-preview-errors]');

    $rects = sideBySideRects($webpage, '[data-template-body]', '[data-template-preview-errors]');

    expect($rects['[data-template-preview-errors]']['left'])->toBeGreaterThan($rects['[data-template-body]']['right']);
});

test('gives the message box at least 400 px at 1280 px', function (): void {
    $chatTemplate = sideBySideTemplate();

    $webpage = visit(route('ai.templates.edit', $chatTemplate, absolute: false))->resize(1280, 800);

    expect(sideBySideRects($webpage, '[data-template-body]')['[data-template-body]']['width'])->toBeGreaterThanOrEqual(400);
});

test('keeps the template editor in one 900 px column below 1280 px', function (): void {
    $chatTemplate = sideBySideTemplate();

    $webpage = visit(route('ai.templates.edit', $chatTemplate, absolute: false))
        ->resize(1279, 800)
        ->assertVisible('[data-template-preview-section]');

    $rects = sideBySideRects($webpage, '[data-template-editor]', '[data-template-body]', '[data-template-preview-section]');

    expect($rects['[data-template-editor]']['width'])->toBeLessThanOrEqual(900)
        ->and($rects['[data-template-preview-section]']['top'])->toBeGreaterThan($rects['[data-template-body]']['bottom']);
});

test('shows Scoring beside the case inputs from 1280 px', function (): void {
    test()->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();

    $webpage = visit(route('admin.benchmarks.cases.edit', $benchmarkCase, absolute: false))
        ->resize(1440, 900)
        ->assertVisible('[data-benchmark-scoring]');

    $rects = sideBySideRects($webpage, '[data-benchmark-inputs]', '[data-benchmark-basics]', '[data-fixture-editor]', '[data-benchmark-scoring]');

    expect($rects['[data-benchmark-scoring]']['left'])->toBeGreaterThan($rects['[data-benchmark-inputs]']['right'])
        ->and($rects['[data-benchmark-basics]']['left'])->toBeGreaterThan($rects['[data-benchmark-inputs]']['right'])
        ->and($rects['[data-benchmark-basics]']['bottom'])->toBeLessThanOrEqual($rects['[data-benchmark-scoring]']['top'] + 1)
        ->and(abs($rects['[data-benchmark-inputs]']['top'] - $rects['[data-benchmark-basics]']['top']))->toBeLessThanOrEqual(4);
});

test('spans the fixtures across both columns below them', function (): void {
    test()->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();

    $webpage = visit(route('admin.benchmarks.cases.edit', $benchmarkCase, absolute: false))
        ->resize(1440, 900)
        ->assertVisible('[data-fixture-editor]');

    $rects = sideBySideRects($webpage, '[data-benchmark-case-form]', '[data-benchmark-inputs]', '[data-benchmark-scoring]', '[data-fixture-editor]');
    $padding = $webpage->script("parseFloat(getComputedStyle(document.querySelector('[data-benchmark-case-form]')).paddingLeft)");
    $contentWidth = $rects['[data-benchmark-case-form]']['width'] - 2 * $padding;

    expect(abs($rects['[data-fixture-editor]']['width'] - $contentWidth))->toBeLessThan(2)
        ->and($rects['[data-fixture-editor]']['top'])->toBeGreaterThanOrEqual($rects['[data-benchmark-inputs]']['bottom'] - 1)
        ->and($rects['[data-fixture-editor]']['top'])->toBeGreaterThanOrEqual($rects['[data-benchmark-scoring]']['bottom'] - 1);
});

test('leaves one 24 px gap between the columns and the fixtures', function (): void {
    test()->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();

    $webpage = visit(route('admin.benchmarks.cases.edit', $benchmarkCase, absolute: false))
        ->resize(1440, 900)
        ->assertVisible('[data-fixture-editor]');

    $rects = sideBySideRects($webpage, '[data-benchmark-inputs]', '[data-benchmark-scoring]', '[data-fixture-editor]');
    $lowest = max($rects['[data-benchmark-inputs]']['bottom'], $rects['[data-benchmark-scoring]']['bottom']);

    expect(abs($rects['[data-fixture-editor]']['top'] - $lowest - 24))->toBeLessThanOrEqual(2);
});

test('keeps the 24 px gap above the fixtures when the case inputs are the taller column', function (): void {
    test()->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create();

    $webpage = visit(route('admin.benchmarks.cases.edit', $benchmarkCase, absolute: false))
        ->resize(1440, 900)
        ->click('[data-benchmark-history-add]')
        ->click('[data-benchmark-history-add]')
        ->click('[data-benchmark-history-add]')
        ->click('[data-benchmark-history-add]');

    $rects = sideBySideRects($webpage, '[data-benchmark-inputs]', '[data-benchmark-scoring]', '[data-fixture-editor]');

    expect($rects['[data-benchmark-inputs]']['bottom'])->toBeGreaterThan($rects['[data-benchmark-scoring]']['bottom'] + 100)
        ->and(abs($rects['[data-fixture-editor]']['top'] - $rects['[data-benchmark-inputs]']['bottom'] - 24))->toBeLessThanOrEqual(2);
});

test('keeps the draft warning full width above both columns', function (): void {
    test()->actingAs(User::factory()->admin()->create());
    $record = AiUsageRecord::factory()->create([
        'agent_class' => MediaAgent::class,
        'kind' => AiUsageKind::Text,
        'prompt_text' => 'Find Dune on my server',
    ]);
    AiToolInvocation::factory()->create([
        'invocation_id' => $record->invocation_id,
        'tool_class' => 'SearchMediaTool',
        'tool_name' => 'SearchMediaTool',
        'arguments' => ['query' => 'Dune'],
        'result' => '{"hits":1}',
        'io_truncated' => true,
    ]);

    $webpage = visit(route('admin.benchmarks.cases.create', ['from_usage' => $record->id], absolute: false))
        ->resize(1440, 900)
        ->assertVisible('[data-benchmark-draft-warnings]');

    $rects = sideBySideRects($webpage, '[data-benchmark-case-form]', '[data-benchmark-draft-warnings]', '[data-benchmark-scoring]');
    $padding = $webpage->script("parseFloat(getComputedStyle(document.querySelector('[data-benchmark-case-form]')).paddingLeft)");
    $contentWidth = $rects['[data-benchmark-case-form]']['width'] - 2 * $padding;

    expect(abs($rects['[data-benchmark-draft-warnings]']['width'] - $contentWidth))->toBeLessThan(2)
        ->and($rects['[data-benchmark-draft-warnings]']['bottom'])->toBeLessThanOrEqual($rects['[data-benchmark-scoring]']['top'] + 1);
});

test('keeps the case form in one 900 px column below 1280 px', function (): void {
    test()->actingAs(User::factory()->admin()->create());
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Title)->judged()->create();

    $webpage = visit(route('admin.benchmarks.cases.edit', $benchmarkCase, absolute: false))
        ->resize(1279, 800)
        ->assertVisible('[data-benchmark-scoring]');

    $rects = sideBySideRects($webpage, '[data-benchmark-case-form]', '[data-fixture-editor]', '[data-benchmark-scoring]');

    expect($rects['[data-benchmark-case-form]']['width'])->toBeLessThanOrEqual(900)
        ->and($rects['[data-benchmark-scoring]']['top'])->toBeGreaterThan($rects['[data-fixture-editor]']['bottom']);
});

function sideBySideLongBody(): string
{
    return implode("\n", array_map(fn (int $i): string => "Line {{v{$i}}}\nand a second line", range(1, 45)));
}
