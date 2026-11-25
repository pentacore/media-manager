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

test('keeps the template preview in view while scrolling', function (): void {
    $chatTemplate = sideBySideTemplate(implode("\n", array_fill(0, 60, 'Check {{anime}} S{{season}}')));

    $webpage = visit(route('ai.templates.edit', $chatTemplate, absolute: false))
        ->resize(1440, 900)
        ->assertVisible('[data-template-preview-section]');

    $webpage->script('window.scrollTo(0, document.documentElement.scrollHeight)');
    $webpage->script('new Promise((resolve) => setTimeout(() => requestAnimationFrame(() => setTimeout(resolve, 0)), 0))');

    $top = $webpage->script("document.querySelector('[data-template-preview-section]').getBoundingClientRect().top");

    expect($top)->toBeGreaterThanOrEqual(0)->toBeLessThan(900);
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
