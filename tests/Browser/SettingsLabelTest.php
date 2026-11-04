<?php

declare(strict_types=1);

use App\Models\User;

/**
 * Returns the label texts of every settings row whose first focusable control
 * is named by neither its row's label (`label.control`) nor an `aria-labelledby`
 * on the control or its group that points at that label.
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
                const control = [...controlColumn.querySelectorAll(focusable)]
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
        ->assertScript(settingsLabelUnlabelledRowsScript(), '[]')
        ->assertScript(settingsLabelForNonLabelableScript(), '[]');
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
