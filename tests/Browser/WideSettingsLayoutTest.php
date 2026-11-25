<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

/**
 * Returns the rounded top and left of every settings row in the OpenRouter
 * routing section's grid, as JSON (`[]` when the section has no grid).
 */
function wideSettingsOpenRouterRowsScript(): string
{
    return <<<'JS_WRAP'
    JSON.stringify([...document.querySelectorAll('[data-openrouter-settings] [data-settings-grid] > [data-settings-row]')]
        .map((row) => {
            const box = row.getBoundingClientRect();
            return [Math.round(box.top), Math.round(box.left)];
        }))
    JS_WRAP;
}

test('lays AI Settings rows two across at 1440 px', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.ai-settings.index', absolute: false))
        ->resize(1440, 900)
        ->assertNoSmoke();

    /** @var list<array{0: int, 1: int}> $rows */
    $rows = json_decode((string) $webpage->script(wideSettingsOpenRouterRowsScript()), true);

    expect($rows)->toHaveCount(5)
        ->and($rows[1][0])->toBe($rows[0][0])
        ->and($rows[1][1])->not->toBe($rows[0][1])
        // Two columns: the third row starts the next line under the first.
        ->and($rows[2][0])->toBeGreaterThan($rows[0][0])
        ->and($rows[2][1])->toBe($rows[0][1]);
});

test('lays AI Settings rows three across at 1920 px', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.ai-settings.index', absolute: false))
        ->resize(1920, 1080)
        ->assertNoSmoke();

    /** @var list<array{0: int, 1: int}> $rows */
    $rows = json_decode((string) $webpage->script(wideSettingsOpenRouterRowsScript()), true);

    expect($rows)->toHaveCount(5)
        ->and($rows[1][0])->toBe($rows[0][0])
        ->and($rows[2][0])->toBe($rows[0][0])
        ->and(array_unique(array_column(array_slice($rows, 0, 3), 1)))->toHaveCount(3);
});

test('keeps the capped single column below 1280 px', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.ai-settings.index', absolute: false))
        ->resize(1024, 800)
        ->assertNoSmoke();

    /** @var list<array{0: int, 1: int}> $rows */
    $rows = json_decode((string) $webpage->script(wideSettingsOpenRouterRowsScript()), true);

    expect($rows)->toHaveCount(5)
        ->and(array_unique(array_column($rows, 1)))->toHaveCount(1)
        ->and($webpage->script('document.querySelector("[data-ai-settings-page]").getBoundingClientRect().width'))
        ->toBeLessThanOrEqual(768);
});

test('lets a wide row span the grid', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->resize(1920, 1080)
        ->assertNoSmoke()
        ->assertScript(<<<'JS'
            (() => {
                const row = document.querySelector('#openrouter_order').closest('[data-settings-row]');
                const grid = row.closest('[data-settings-grid]');
                return grid !== null
                    && Math.round(row.getBoundingClientRect().width) === Math.round(grid.getBoundingClientRect().width);
            })()
            JS, true);
});

test('stacks the label above the control inside a column', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->resize(1440, 900)
        ->assertNoSmoke()
        ->assertScript(<<<'JS'
            (() => {
                const row = document.querySelector('#ai-settings-openrouter-sort').closest('[data-settings-row]');
                const [label, control] = row.children;
                return row.closest('[data-settings-grid]') !== null
                    && label.getBoundingClientRect().bottom <= control.getBoundingClientRect().top;
            })()
            JS, true);
});
