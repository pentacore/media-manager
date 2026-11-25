<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use App\Models\ServiceConnection;
use App\Models\User;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;

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

/**
 * Returns the rounded top, left and width of every element an expression
 * yields (a NodeList or array), as JSON. For grids whose children are plain
 * field blocks rather than `SettingsRow`s.
 */
function wideSettingsBlocksScript(string $blocksExpression): string
{
    return <<<JS_WRAP
    JSON.stringify([...({$blocksExpression})]
        .map((element) => {
            const box = element.getBoundingClientRect();
            return [Math.round(box.top), Math.round(box.left), Math.round(box.width)];
        }))
    JS_WRAP;
}

/**
 * Resizes to 1279 px (the widest viewport below `xl`, where the content area is
 * about 1,023 px and a page's cap is what limits it) and expects the element an
 * expression yields to be at most `$max` px wide. At 1024 px the content area is
 * only about 768 px, so a width check there passes with or without the cap.
 *
 * @param  Webpage|AwaitableWebpage  $webpage
 */
function expectWidthCapBelowXl($webpage, string $elementExpression, int $max): void
{
    $webpage->resize(1279, 800);

    expect($webpage->script("Math.round(({$elementExpression}).getBoundingClientRect().width)"))->toBeLessThanOrEqual($max);
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
        ->and(array_unique(array_column($rows, 1)))->toHaveCount(1);

    expectWidthCapBelowXl($webpage, 'document.querySelector("[data-ai-settings-page]")', 768);
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

    expect(array_unique(array_column($narrow, 1)))->toHaveCount(1);

    expectWidthCapBelowXl($webpage, 'document.querySelector("[data-decision-agent-page]")', 768);
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

    expect(array_unique(array_column($narrow, 1)))->toHaveCount(1);

    expectWidthCapBelowXl($webpage, 'document.querySelector("[data-media-replacement-settings-page]")', 768);
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

    expect(array_unique(array_column($narrow, 1)))->toHaveCount(1);

    // The settings section keeps its 2xl cap below xl.
    expectWidthCapBelowXl($webpage, 'document.querySelector("[data-settings-section]")', 672);
})->with([
    'profile' => ['profile.edit', ['name', 'email']],
    'preferences' => ['settings.preferences.edit', ['preferences-time-format', 'preferences-date-format']],
    'security' => ['security.edit', ['current_password', 'password']],
]);

test('spans the Save row across the grid and hides the dividers at xl', function (string $route): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route($route, absolute: false))
        ->resize(1440, 900)
        ->assertNoSmoke();

    // The Save row is the last child of the grid and as wide as the grid; every
    // divider the grid holds is hidden from xl.
    $webpage->assertScript(<<<'JS'
        (() => {
            const grid = document.querySelector('[data-settings-grid]');
            const save = grid.lastElementChild;
            const dividers = [...grid.querySelectorAll(':scope > [data-slot="separator"]')];
            return save.querySelector('button[type="submit"]') !== null
                && Math.round(save.getBoundingClientRect().width) === Math.round(grid.getBoundingClientRect().width)
                && dividers.length > 0
                && dividers.every((divider) => getComputedStyle(divider).display === 'none');
        })()
        JS, true);

    $webpage->resize(1024, 800)->assertScript(<<<'JS'
        (() => {
            const grid = document.querySelector('[data-settings-grid]');
            const dividers = [...grid.querySelectorAll(':scope > [data-slot="separator"]')];
            return dividers.length > 0
                && dividers.every((divider) => divider.getBoundingClientRect().height > 0);
        })()
        JS, true);
})->with(['profile.edit', 'settings.preferences.edit', 'security.edit']);

test('keeps the Appearance and Notifications settings capped at every width', function (string $route, int $width): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route($route, absolute: false))
        ->resize($width, 900)
        ->assertNoSmoke()
        ->assertScript('document.querySelector("[data-settings-section]").getBoundingClientRect().width <= 672', true);
})->with(['appearance.edit', 'settings.notifications.edit'])->with([1279, 1440, 1920]);

test('keeps the blocks that are not a grid capped on the wide settings pages at 1440 px', function (string $route): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route($route, absolute: false))
        ->resize(1440, 900)
        ->assertNoSmoke()
        ->assertScript(<<<'JS'
            (() => {
                const blocks = [...document.querySelectorAll('[data-settings-capped]')];
                return blocks.length > 0
                    && document.querySelector('[data-settings-section]').getBoundingClientRect().width > 672
                    && blocks.every((block) => block.getBoundingClientRect().width <= 672);
            })()
            JS, true);
})->with(['profile.edit', 'security.edit']);

test('keeps the Media Replacement guidance blocks and the Decision Agent overrides capped at 1920 px', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.media-replacement.index', absolute: false))
        ->resize(1920, 1080)
        ->assertNoSmoke()
        ->assertScript(<<<'JS'
            (() => {
                const blocks = [...document.querySelectorAll('[data-settings-capped]')];
                return blocks.length > 0
                    && document.querySelector('[data-media-replacement-settings-page]').getBoundingClientRect().width > 672
                    && blocks.every((block) => block.getBoundingClientRect().width <= 672);
            })()
            JS, true);

    // Below xl the guidance blocks keep the page's own width: the cap starts at xl.
    visit(route('admin.media-replacement.index', absolute: false))
        ->resize(1279, 800)
        ->assertNoSmoke()
        ->assertScript(<<<'JS'
            (() => {
                const block = document.querySelector('[data-settings-capped]');
                return block !== null
                    && Math.round(block.getBoundingClientRect().width) === Math.round(block.parentElement.getBoundingClientRect().width);
            })()
            JS, true);

    visit(route('admin.decision-agent.index', absolute: false))
        ->resize(1920, 1080)
        ->assertNoSmoke()
        ->assertScript(<<<'JS'
            (() => {
                const overrides = document.querySelector('[data-second-opinion-event-overrides]');
                return overrides !== null && overrides.getBoundingClientRect().width <= 768;
            })()
            JS, true);
});

test('lays the connection form fields two across at 1440 px and 1920 px and in one column at 1024 px', function (string $route): void {
    $this->actingAs(User::factory()->admin()->create());
    $sonarr = ServiceConnection::factory()->sonarr()->create();

    $url = route($route, $route === 'admin.connections.edit' ? ['serviceConnection' => $sonarr] : [], absolute: false);

    // The service type, the display name and the URL.
    $firstThree = "document.querySelector('[data-settings-grid]').querySelectorAll(':scope > :nth-child(-n+3)')";
    $formWidth = 'document.querySelector("[data-connection-cancel]").closest("form").getBoundingClientRect().width';

    $webpage = visit($url)->resize(1440, 900)->assertNoSmoke();

    /** @var list<array{0: int, 1: int, 2: int}> $blocks */
    $blocks = json_decode((string) $webpage->script(wideSettingsBlocksScript($firstThree)), true);

    expect($blocks)->toHaveCount(3)
        ->and($blocks[1][0])->toBe($blocks[0][0])
        ->and($blocks[1][1])->toBeGreaterThan($blocks[0][1])
        ->and($webpage->script($formWidth))->toBeGreaterThan(672);

    // A short form stops at two columns: the URL starts the next line.
    $webpage->resize(1920, 1080);

    /** @var list<array{0: int, 1: int, 2: int}> $wide */
    $wide = json_decode((string) $webpage->script(wideSettingsBlocksScript($firstThree)), true);

    expect($wide[1][0])->toBe($wide[0][0])
        ->and($wide[1][1])->toBeGreaterThan($wide[0][1])
        ->and($wide[2][0])->toBeGreaterThan($wide[0][0])
        ->and($wide[2][1])->toBe($wide[0][1]);

    $webpage->resize(1024, 800);

    /** @var list<array{0: int, 1: int, 2: int}> $narrow */
    $narrow = json_decode((string) $webpage->script(wideSettingsBlocksScript($firstThree)), true);

    expect($narrow[1][1])->toBe($narrow[0][1])
        ->and($narrow[1][0])->toBeGreaterThan($narrow[0][0]);

    // The page keeps its max-w-2xl cap below xl (672 px plus the page padding).
    expectWidthCapBelowXl($webpage, 'document.querySelector("[data-connection-cancel]").closest("form")', 672);
})->with(['admin.connections.create', 'admin.connections.edit']);

test('keeps the connection blocks that are not fields capped on a wide edit page', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $sonarr = ServiceConnection::factory()->sonarr()->create();

    visit(route('admin.connections.edit', ['serviceConnection' => $sonarr], absolute: false))
        ->resize(1920, 1080)
        ->assertNoSmoke()
        ->assertScript(<<<'JS'
            (() => {
                const blocks = [...document.querySelectorAll('[data-settings-capped]')];
                const slots = document.querySelector('[data-subtitle-audit-search-slots]');
                return blocks.length > 0
                    && blocks.every((block) => block.getBoundingClientRect().width <= 672)
                    // The repair-searches help text is a long paragraph: it keeps the cap too.
                    && blocks.includes(slots);
            })()
            JS, true);
});

test('lays the benchmark schedule form two across at 1440 px and in one column at 1024 px', function (): void {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.benchmarks.schedules.create', absolute: false))
        ->resize(1440, 900)
        ->assertNoSmoke();

    $firstTwo = "document.querySelector('[data-settings-grid]').querySelectorAll(':scope > :nth-child(-n+2)')";

    /** @var list<array{0: int, 1: int, 2: int}> $blocks */
    $blocks = json_decode((string) $webpage->script(wideSettingsBlocksScript($firstTwo)), true);

    expect($blocks)->toHaveCount(2)
        ->and($blocks[1][0])->toBe($blocks[0][0])
        ->and($blocks[1][1])->toBeGreaterThan($blocks[0][1])
        ->and($webpage->script('document.querySelector("[data-benchmark-schedule-save]").closest("form").getBoundingClientRect().width'))
        ->toBeGreaterThan(896);

    // Name, task and tags fill the first line at 1800 px and wider.
    $webpage->resize(1920, 1080);

    /** @var list<array{0: int, 1: int, 2: int}> $three */
    $three = json_decode((string) $webpage->script(wideSettingsBlocksScript(
        "document.querySelector('[data-settings-grid]').querySelectorAll(':scope > :nth-child(-n+3)')",
    )), true);

    expect(array_unique(array_column($three, 0)))->toHaveCount(1)
        ->and(array_unique(array_column($three, 1)))->toHaveCount(3);

    $webpage->resize(1024, 800);

    /** @var list<array{0: int, 1: int, 2: int}> $narrow */
    $narrow = json_decode((string) $webpage->script(wideSettingsBlocksScript($firstTwo)), true);

    expect($narrow[1][1])->toBe($narrow[0][1])
        ->and($narrow[1][0])->toBeGreaterThan($narrow[0][0]);

    expectWidthCapBelowXl($webpage, 'document.querySelector("[data-benchmark-schedule-save]").closest("form")', 896);
});

test('keeps the new benchmark run page capped at 896 px at every width', function (int $width): void {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.benchmarks.runs.create', absolute: false))
        ->resize($width, 900)
        ->assertNoSmoke()
        ->assertScript('Math.round(document.querySelector("[data-benchmark-run-page]").getBoundingClientRect().width) <= 896', true);
})->with([1279, 1440, 1920]);

test('lays AI Models task rows two across at 1440 px and never three across at 1920 px', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-4.1']);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    $this->actingAs(User::factory()->admin()->create());

    $tasks = "document.querySelectorAll('form [data-settings-grid] > [data-task]')";

    $webpage = visit(route('admin.ai-models.index', absolute: false))
        ->resize(1440, 900)
        ->assertNoSmoke();

    /** @var list<array{0: int, 1: int, 2: int}> $rows */
    $rows = json_decode((string) $webpage->script(wideSettingsBlocksScript($tasks)), true);

    expect(count($rows))->toBeGreaterThan(3)
        ->and($rows[1][0])->toBe($rows[0][0])
        ->and($rows[1][1])->toBeGreaterThan($rows[0][1])
        // The label sits above the control inside a column.
        ->and($webpage->script("(() => { const row = document.querySelector('[data-task]'); const [label, control] = row.children; return label.getBoundingClientRect().bottom <= control.getBoundingClientRect().top; })()"))
        ->toBeTrue();

    $webpage->resize(1920, 1080);

    /** @var list<array{0: int, 1: int, 2: int}> $wide */
    $wide = json_decode((string) $webpage->script(wideSettingsBlocksScript($tasks)), true);

    expect(array_unique(array_column($wide, 1)))->toHaveCount(2);

    $webpage->resize(1024, 800);

    /** @var list<array{0: int, 1: int, 2: int}> $narrow */
    $narrow = json_decode((string) $webpage->script(wideSettingsBlocksScript($tasks)), true);

    expect(array_unique(array_column($narrow, 1)))->toHaveCount(1)
        // The old label-beside-control row and the max-w-3xl cap (768 px) stay.
        ->and($webpage->script("(() => { const row = document.querySelector('[data-task]'); const [label, control] = row.children; return Math.abs(label.getBoundingClientRect().top - control.getBoundingClientRect().top) <= 2; })()"))
        ->toBeTrue();

    expectWidthCapBelowXl($webpage, 'document.querySelector("form [data-settings-grid]").closest("form")', 768);
});
