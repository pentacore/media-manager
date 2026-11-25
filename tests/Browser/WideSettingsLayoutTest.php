<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    // The Security page asks for a password confirmation otherwise.
    config()->set('fortify-options.two-factor-authentication.confirmPassword', false);
});

/**
 * Returns the rounded top and left of every settings row an expression yields
 * (a NodeList or array of elements; each is resolved to its own or nearest
 * `[data-settings-row]`), as JSON.
 */
function wideSettingsRowsScript(string $rowsExpression): string
{
    return <<<JS_WRAP
    JSON.stringify([...({$rowsExpression})]
        .map((element) => {
            const box = element.closest('[data-settings-row]').getBoundingClientRect();
            return [Math.round(box.top), Math.round(box.left)];
        }))
    JS_WRAP;
}

/** The rows that are direct children of the first grid matching `$gridSelector`. */
function wideSettingsGridRowsScript(string $gridSelector): string
{
    return wideSettingsRowsScript(
        "document.querySelector('{$gridSelector}').querySelectorAll(':scope > [data-settings-row]')",
    );
}

/** One element per id, so a page can name the rows it measures. */
function wideSettingsIdRowsScript(string ...$ids): string
{
    $list = implode(',', array_map(fn (string $id): string => "document.getElementById('{$id}')", $ids));

    return wideSettingsRowsScript("[{$list}]");
}

test('lays AI Settings rows two across at 1440 px', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.ai-settings.index', absolute: false))
        ->resize(1440, 900)
        ->assertNoSmoke();

    /** @var list<array{0: int, 1: int}> $rows */
    $rows = json_decode((string) $webpage->script(wideSettingsGridRowsScript('[data-openrouter-settings] [data-settings-grid]')), true);

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
    $rows = json_decode((string) $webpage->script(wideSettingsGridRowsScript('[data-openrouter-settings] [data-settings-grid]')), true);

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
    $rows = json_decode((string) $webpage->script(wideSettingsGridRowsScript('[data-openrouter-settings] [data-settings-grid]')), true);

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
                const [label, control] = row.children;
                const labelBox = label.getBoundingClientRect();
                const controlBox = control.getBoundingClientRect();
                return grid !== null
                    && Math.round(row.getBoundingClientRect().width) === Math.round(grid.getBoundingClientRect().width)
                    // The label stays beside the control, not above it.
                    && Math.abs(Math.round(labelBox.top) - Math.round(controlBox.top)) <= 2
                    && controlBox.left > labelBox.left;
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

test('lays out the first AI Settings grid and the advanced tools grid two across at 1440 px', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.ai-settings.index', absolute: false))
        ->resize(1440, 900)
        ->assertNoSmoke();

    // Mode / Chat timeout / Attachments.
    /** @var list<array{0: int, 1: int}> $top */
    $top = json_decode((string) $webpage->script(wideSettingsGridRowsScript('[data-ai-settings-page] [data-settings-grid]')), true);

    expect($top)->toHaveCount(3)
        ->and($top[1][0])->toBe($top[0][0])
        ->and($top[1][1])->not->toBe($top[0][1])
        ->and($top[2][0])->toBeGreaterThan($top[0][0])
        ->and($top[2][1])->toBe($top[0][1]);

    /** @var list<array{0: int, 1: int}> $tools */
    $tools = json_decode((string) $webpage->script(wideSettingsRowsScript(
        "document.querySelector('[data-advanced-tools]').closest('[data-settings-grid]').querySelectorAll(':scope > [data-settings-row]')",
    )), true);

    expect($tools)->toHaveCount(4)
        ->and($tools[1][0])->toBe($tools[0][0])
        ->and($tools[1][1])->not->toBe($tools[0][1])
        ->and($tools[2][0])->toBeGreaterThan($tools[0][0])
        ->and($tools[2][1])->toBe($tools[0][1]);
});

test('lays the Decision Agent settings two across at 1440 px and in one column at 1024 px', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $ids = ['decision-agent-manual-import', 'decision-agent-notify', 'decision-agent-max-actions', 'decision-agent-run-time-limit'];

    $webpage = visit(route('admin.decision-agent.index', absolute: false))
        ->resize(1440, 900)
        ->assertNoSmoke();

    /** @var list<array{0: int, 1: int}> $rows */
    $rows = json_decode((string) $webpage->script(wideSettingsIdRowsScript(...$ids)), true);

    expect($rows)->toHaveCount(4)
        ->and($rows[1][0])->toBe($rows[0][0])
        ->and($rows[1][1])->not->toBe($rows[0][1])
        ->and($rows[2][0])->toBeGreaterThan($rows[0][0])
        ->and($rows[3][0])->toBe($rows[2][0]);

    $webpage->resize(1024, 800);

    /** @var list<array{0: int, 1: int}> $narrow */
    $narrow = json_decode((string) $webpage->script(wideSettingsIdRowsScript(...$ids)), true);

    expect(array_unique(array_column($narrow, 1)))->toHaveCount(1)
        ->and($webpage->script('document.querySelector("[data-decision-agent-page]").getBoundingClientRect().width'))
        ->toBeLessThanOrEqual(768);
});

test('lays the Media Replacement settings two across at 1440 px and in one column at 1024 px', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $ids = ['media-replacement-automatic-selection', 'media-replacement-confidence-threshold'];

    $webpage = visit(route('admin.media-replacement.index', absolute: false))
        ->resize(1440, 900)
        ->assertNoSmoke();

    /** @var list<array{0: int, 1: int}> $rows */
    $rows = json_decode((string) $webpage->script(wideSettingsIdRowsScript(...$ids)), true);

    expect($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe($rows[0][0])
        ->and($rows[1][1])->not->toBe($rows[0][1]);

    $webpage->resize(1024, 800);

    /** @var list<array{0: int, 1: int}> $narrow */
    $narrow = json_decode((string) $webpage->script(wideSettingsIdRowsScript(...$ids)), true);

    expect(array_unique(array_column($narrow, 1)))->toHaveCount(1)
        ->and($webpage->script('document.querySelector("[data-media-replacement-settings-page]").getBoundingClientRect().width'))
        ->toBeLessThanOrEqual(768);
});

test('lays member settings two across, one column at 1024 px', function (string $route, array $ids): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route($route, absolute: false))
        ->resize(1440, 900)
        ->assertNoSmoke();

    /** @var list<array{0: int, 1: int}> $rows */
    $rows = json_decode((string) $webpage->script(wideSettingsIdRowsScript(...$ids)), true);

    expect($rows)->toHaveCount(count($ids))
        ->and($rows[1][0])->toBe($rows[0][0])
        ->and($rows[1][1])->not->toBe($rows[0][1]);

    $webpage->resize(1024, 800);

    /** @var list<array{0: int, 1: int}> $narrow */
    $narrow = json_decode((string) $webpage->script(wideSettingsIdRowsScript(...$ids)), true);

    expect(array_unique(array_column($narrow, 1)))->toHaveCount(1)
        // The settings section keeps its 2xl cap below xl.
        ->and($webpage->script('document.querySelector("[data-settings-grid]").closest("section").getBoundingClientRect().width'))
        ->toBeLessThanOrEqual(672);
})->with([
    'profile' => ['profile.edit', ['name', 'email']],
    'preferences' => ['settings.preferences.edit', ['preferences-time-format', 'preferences-date-format']],
    'security' => ['security.edit', ['current_password', 'password']],
]);

test('keeps the Appearance and Notifications settings capped at 1024 px', function (string $route): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route($route, absolute: false))
        ->resize(1024, 800)
        ->assertNoSmoke()
        ->assertScript('document.querySelector("section.max-w-2xl").getBoundingClientRect().width <= 672', true);
})->with(['appearance.edit', 'settings.notifications.edit']);
