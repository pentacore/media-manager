<?php

declare(strict_types=1);

use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;
use App\Models\ChatTemplate;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Settings\DecisionAgentSettings;

/**
 * Returns the label texts of every settings row whose first focusable control
 * (the control column itself when it is one) is named by neither its row's
 * label (`label.control`) nor an `aria-labelledby` on the control or its group
 * that points at that label.
 */
function settingsLabelUnlabelledRowsScript(): string
{
    return <<<'JS_WRAP'
        (() => {
            const focusable = 'input:not([type="hidden"]), select, textarea, button, [role="switch"], [role="combobox"]';
            const pointsAt = (element, label) => (element?.getAttribute('aria-labelledby') ?? '')
                .split(/\s+/)
                .filter(Boolean)
                .some((id) => document.getElementById(id) === label);
            return JSON.stringify([...document.querySelectorAll('[data-settings-row]')].flatMap((row) => {
                const [labelColumn, controlColumn] = row.children;
                const label = labelColumn.firstElementChild;
                const control = [controlColumn, ...controlColumn.querySelectorAll(focusable)]
                    .filter((element) => element.matches(focusable))
                    .find((element) => element.tabIndex >= 0 && !element.closest('[aria-hidden="true"]'));
                if (!control) {
                    return [];
                }
                const group = control.closest('[role="group"]');
                const isLabelled = (label.tagName === 'LABEL' && label.control === control)
                    || pointsAt(control, label)
                    || (group !== null && controlColumn.contains(group) && pointsAt(group, label));
                return isLabelled ? [] : [label.textContent.trim()];
            }));
        })()
    JS_WRAP;
}

/**
 * Returns the text of every `label[for]` whose target is missing or is not a
 * labelable element (a `<label for>` that points at a group `div` is invalid).
 */
function settingsLabelForNonLabelableScript(): string
{
    return 'JSON.stringify([...document.querySelectorAll("label[for]")].filter((label) => label.control === null).map((label) => label.textContent.trim()))';
}

/**
 * Returns "<group label>: <control>" for every focusable control inside a
 * labelled control group that has no name of its own (no `aria-label`, no
 * resolving `aria-labelledby`, no `<label>`, and for a plain button no text),
 * and "<id>: missing label" for a group whose label id does not resolve.
 */
function settingsLabelUnnamedGroupControlsScript(): string
{
    return <<<'JS_WRAP'
        (() => {
            const focusable = 'input:not([type="hidden"]), select, textarea, button, [role="switch"], [role="combobox"]';
            const resolves = (element) => {
                const ids = (element.getAttribute('aria-labelledby') ?? '').split(/\s+/).filter(Boolean);
                return ids.length > 0 && ids.every((id) => (document.getElementById(id)?.textContent.trim() ?? '') !== '');
            };
            const isNamed = (control) => (control.getAttribute('aria-label') ?? '').trim() !== ''
                || resolves(control)
                || (control.labels?.length ?? 0) > 0
                || (control.tagName === 'BUTTON'
                    && !['combobox', 'switch', 'checkbox'].includes(control.getAttribute('role') ?? '')
                    && control.textContent.trim() !== '');
            return JSON.stringify([...document.querySelectorAll('main [role="group"][aria-labelledby]')].flatMap((group) => {
                if (!resolves(group)) {
                    return [`${group.getAttribute('aria-labelledby')}: missing label`];
                }
                const groupLabel = document.getElementById(group.getAttribute('aria-labelledby').split(/\s+/)[0]).textContent.trim();
                return [...group.querySelectorAll(focusable)]
                    .filter((control) => control.tabIndex >= 0 && !control.closest('[aria-hidden="true"]') && !isNamed(control))
                    .map((control) => `${groupLabel}: ${control.outerHTML.slice(0, 80)}`);
            }));
        })()
    JS_WRAP;
}

/**
 * Returns the text of every hint of a linked `Field` (one whose label has an
 * id) that no control or control group describes with it: its id must appear
 * in the `aria-describedby` of an input, select, textarea, button, switch,
 * combobox or group. Read-only fields keep their hints undescribed.
 */
function settingsLabelUndescribedHintsScript(): string
{
    return <<<'JS_WRAP'
        (() => {
            const describable = 'input, select, textarea, button, [role="switch"], [role="combobox"], [role="group"]';
            return JSON.stringify([...document.querySelectorAll('main [data-field-hint]')].flatMap((hint) => {
                if (hint.parentElement.firstElementChild.id === '') {
                    return [];
                }
                const isDescribed = hint.id !== '' && document.getElementById(hint.id) === hint
                    && [...document.querySelectorAll('[aria-describedby]')].some((element) => element.matches(describable)
                        && element.getAttribute('aria-describedby').split(/\s+/).includes(hint.id));
                return isDescribed ? [] : [hint.textContent.trim()];
            }));
        })()
    JS_WRAP;
}

/**
 * Seeds the admin settings pages so every row renders: Jev as the second
 * opinion engine with a configured classifier (the threshold input shows), and
 * two allowlisted events, one with a second-opinion override, so the override
 * controls and the add-override select render inside the labelled groups.
 */
function settingsLabelSeedAdminPages(): void
{
    config([
        'mediamanager.ai.enabled' => true,
        'mediamanager.ai.classification.provider' => 'openrouter',
        'ai.providers.openrouter.key' => 'sk-test',
        'mediamanager.monitoring.uptime_kuma_push_url' => null,
    ]);
    $decisionAgentSettings = resolve(DecisionAgentSettings::class);
    $decisionAgentSettings->setSecondOpinionEngine(SecondOpinionEngine::Jev);
    $decisionAgentSettings->setEventAllowlist(['sonarr:Download', 'radarr:Grab']);
    $decisionAgentSettings->setSecondOpinionEventOverrides(['sonarr:Download' => ['mode' => SecondOpinionMode::Hold, 'engine' => null, 'jev_threshold' => null]]);
}

/**
 * Returns whether the trigger at `$selector` is named, through its
 * `aria-labelledby`, by text holding both `$name` and its own value text.
 */
function settingsLabelNameKeepsValueScript(string $selector, string $name): string
{
    $selectorJson = json_encode($selector, JSON_THROW_ON_ERROR);
    $nameJson = json_encode($name, JSON_THROW_ON_ERROR);

    return <<<JS_WRAP
        (() => {
            const trigger = document.querySelector({$selectorJson});
            const value = trigger.textContent.trim();
            const name = (trigger.getAttribute('aria-labelledby') ?? '').split(/\s+/).filter(Boolean)
                .map((id) => document.getElementById(id)?.textContent.trim() ?? '').join(' ');
            return value !== '' && name.includes({$nameJson}) && name.includes(value);
        })()
    JS_WRAP;
}

/**
 * Returns whether the AI Models page shows at least two add-event-override
 * selects (one per task that accepts event scopes) and no two share a name.
 */
function settingsLabelUniqueAddOverrideNamesScript(): string
{
    return <<<'JS_WRAP'
        (() => {
            const names = [...document.querySelectorAll('main [role="combobox"][aria-label$=" event override"]')]
                .map((trigger) => trigger.getAttribute('aria-label'));
            return names.length >= 2 && new Set(names).size === names.length;
        })()
    JS_WRAP;
}

/**
 * Returns the labels whose box differs by more than 0.5 px from a `<div>` with
 * the same classes and text placed beside it, i.e. the old Field label.
 */
function settingsLabelBoxDriftScript(): string
{
    return <<<'JS_WRAP'
        (() => JSON.stringify([...document.querySelectorAll('[data-settings-row] label[id$="-label"]')].flatMap((label) => {
            const twin = document.createElement('div');
            twin.className = label.className.split(/\s+/).filter((name) => name !== 'block').join(' ');
            twin.textContent = label.textContent;
            label.after(twin);
            const labelBox = label.getBoundingClientRect();
            const twinBox = twin.getBoundingClientRect();
            twin.remove();
            return Math.abs(labelBox.width - twinBox.width) <= 0.5 && Math.abs(labelBox.height - twinBox.height) <= 0.5
                ? []
                : [label.textContent.trim()];
        })))()
    JS_WRAP;
}

beforeEach(function (): void {
    config()->set('fortify-options.two-factor-authentication.confirmPassword', false);
});

test('every editable settings row on the member settings pages is named by its label', function (string $routeName): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route($routeName, absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertPresent('[data-settings-row]')
        ->assertPresent('main [data-field-hint]')
        ->assertScript(settingsLabelUnlabelledRowsScript(), '[]')
        ->assertScript(settingsLabelForNonLabelableScript(), '[]')
        ->assertScript(settingsLabelUndescribedHintsScript(), '[]');
})->with([
    'profile' => ['profile.edit'],
    'security' => ['security.edit'],
    'preferences' => ['settings.preferences.edit'],
]);

test('a linked settings label keeps the old label box at desktop width', function (string $routeName): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route($routeName, absolute: false))
        ->assertNoSmoke()
        ->assertScript('document.querySelectorAll("[data-settings-row] label[id$=\"-label\"]").length > 0', true)
        ->assertScript(settingsLabelBoxDriftScript(), '[]');
})->with([
    'profile' => ['profile.edit'],
    'security' => ['security.edit'],
    'preferences' => ['settings.preferences.edit'],
]);

test('the preferences selects keep their value in their accessible name and the timezone pair is one group', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('settings.preferences.edit', absolute: false))
        ->assertNoSmoke()
        ->assertScript('document.querySelector("#preferences-time-format").getAttribute("aria-labelledby")', 'preferences-time-format-label preferences-time-format')
        ->assertScript('document.querySelector("#preferences-timezone").getAttribute("role")', 'group')
        ->assertScript('document.querySelector("#preferences-timezone-select").getAttribute("aria-labelledby")', 'preferences-timezone-label preferences-timezone-select');
});

test('every editable settings row on the admin settings pages is named by its label', function (string $routeName): void {
    settingsLabelSeedAdminPages();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route($routeName, absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertPresent('[data-settings-row]')
        ->assertScript(settingsLabelUnlabelledRowsScript(), '[]')
        ->assertScript(settingsLabelForNonLabelableScript(), '[]')
        ->assertScript(settingsLabelUnnamedGroupControlsScript(), '[]')
        ->assertScript(settingsLabelUndescribedHintsScript(), '[]');
})->with([
    'ai settings' => ['admin.ai-settings.index'],
    'decision agent' => ['admin.decision-agent.index'],
    'media replacement' => ['admin.media-replacement.index'],
    'servers' => ['admin.servers.index'],
]);

test('a linked admin settings label keeps the old label box at desktop width', function (string $routeName): void {
    settingsLabelSeedAdminPages();
    $this->actingAs(User::factory()->admin()->create());

    visit(route($routeName, absolute: false))
        ->assertNoSmoke()
        ->assertScript('document.querySelectorAll("[data-settings-row] label[id$=\"-label\"]").length > 0', true)
        ->assertScript(settingsLabelBoxDriftScript(), '[]');
})->with([
    'ai settings' => ['admin.ai-settings.index'],
    'decision agent' => ['admin.decision-agent.index'],
    'media replacement' => ['admin.media-replacement.index'],
    'servers' => ['admin.servers.index'],
]);

test('the admin settings groups and selects point at their row label', function (): void {
    settingsLabelSeedAdminPages();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertScript('document.querySelector("#ai-settings-mode").getAttribute("aria-labelledby")', 'ai-settings-mode-label ai-settings-mode')
        ->assertScript('document.querySelector("#ai-settings-decision-gate").getAttribute("role")', 'group')
        ->assertScript('document.querySelector("#ai-settings-decision-gate-label").tagName', 'DIV')
        ->assertScript('document.querySelector("#ai-settings-ignored-providers").getAttribute("aria-labelledby")', 'ai-settings-ignored-providers-label')
        ->assertScript('document.querySelector("[data-rate-limits-toggle]").getAttribute("aria-labelledby")', 'ai-settings-rate-limits-label');

    visit(route('admin.decision-agent.index', absolute: false))
        ->assertNoSmoke()
        ->assertScript('document.querySelector("#decision-agent-second-opinion-engine").getAttribute("aria-labelledby")', 'decision-agent-second-opinion-label decision-agent-second-opinion-engine')
        ->assertScript('document.querySelector("[data-second-opinion-threshold]").labels.length', 1)
        ->assertPresent('[data-second-opinion-override="sonarr:Download"]')
        ->assertPresent('[data-add-second-opinion-override] button')
        ->assertScript('document.querySelector("#decision-agent-event-allowlist").getAttribute("role")', 'group');
});

test('the chat template editor names its toggles and its preset and tool group controls', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    ServiceConnection::factory()->prowlarr()->create();
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->create();
    $this->actingAs($admin);

    $webpage = visit(route('ai.templates.edit', $chatTemplate, absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-template-preset]')
        ->assertVisible('[data-template-tool-group="indexers"]');

    $webpage->assertScript('document.querySelector("#template-auto-send").getAttribute("aria-labelledby")', 'template-auto-send-label')
        ->assertScript('document.querySelector("#template-pinned").labels[0].textContent.trim()', 'Show on new chats')
        ->assertScript(settingsLabelNameKeepsValueScript('#template-preset-reasoning', 'Reasoning'), true)
        ->assertScript(settingsLabelNameKeepsValueScript('#template-preset-model', 'Model'), true)
        ->assertScript('document.querySelectorAll("main [role=\"group\"][aria-labelledby]").length', 2)
        ->assertScript(settingsLabelForNonLabelableScript(), '[]')
        ->assertScript(settingsLabelUnnamedGroupControlsScript(), '[]')
        ->assertScript(settingsLabelUndescribedHintsScript(), '[]');
});

test('the ai models rows and failover are named control groups', function (): void {
    config([
        'mediamanager.ai.enabled' => true,
        'ai.default' => 'openai',
        'ai.providers.openai.key' => 'sk-test',
    ]);
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:Download']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->assertPresent('[data-add-event-override] button')
        ->assertScript(settingsLabelNameKeepsValueScript('[data-task="chat"] [data-reasoning-select] button', 'Chat reasoning'), true)
        ->assertScript('document.querySelector("[data-task=\"chat\"] [data-reasoning-select] [data-select-name]").getAttribute("aria-hidden")', 'true')
        ->assertScript('[...document.querySelectorAll("main [data-select-name]")].filter((name) => name.getAttribute("aria-hidden") !== "true").length', 0)
        ->assertScript(settingsLabelUniqueAddOverrideNamesScript(), true)
        ->assertScript('document.querySelector("[data-task=\"failover\"] [role=\"group\"]").getAttribute("aria-labelledby")', 'ai-models-failover-label')
        ->assertScript('document.querySelector("[data-failover-provider]").getAttribute("aria-labelledby")', 'ai-models-failover-label ai-models-failover-provider')
        ->assertScript('document.querySelector("[data-task=\"chat\"] [role=\"group\"]").getAttribute("aria-labelledby")', 'ai-models-chat-label')
        ->assertScript(settingsLabelForNonLabelableScript(), '[]')
        ->assertScript(settingsLabelUnnamedGroupControlsScript(), '[]')
        ->assertScript(settingsLabelUndescribedHintsScript(), '[]');
});

