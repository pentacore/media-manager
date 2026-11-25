<?php

declare(strict_types=1);

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

test('scrolls a preview taller than the viewport inside itself', function (): void {
    $chatTemplate = sideBySideTemplate();

    $webpage = visit(route('ai.templates.edit', $chatTemplate, absolute: false))
        ->resize(1440, 900)
        ->fill('[data-template-body]', sideBySideLongBody())
        ->assertVisible('[data-variable-row="v20"]');

    $webpage->script("new Promise((resolve) => { const poll = () => document.querySelector('[data-template-preview]').textContent.includes('v30') || document.querySelector('[data-template-preview]').textContent.includes('Line') && document.querySelector('[data-template-preview]').textContent.length > 200 ? resolve(true) : setTimeout(poll, 100); poll(); })");
    $webpage->script('window.scrollTo(0, document.documentElement.scrollHeight)');

    $metrics = $webpage->script(<<<'JS'
        (() => {
            const el = document.querySelector('[data-template-preview-section]');

            return { scroll: el.scrollHeight, client: el.clientHeight, bottom: el.getBoundingClientRect().bottom, inner: window.innerHeight, overflow: getComputedStyle(el).overflowY };
        })()
    JS);

    expect($metrics['scroll'])->toBeGreaterThan($metrics['client'])
        ->and($metrics['bottom'])->toBeLessThanOrEqual($metrics['inner'])
        ->and($metrics['overflow'])->toBe('auto');
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

function sideBySideLongBody(): string
{
    return implode("\n", array_map(fn (int $i): string => "Line {{v{$i}}}\nand a second line", range(1, 45)));
}
