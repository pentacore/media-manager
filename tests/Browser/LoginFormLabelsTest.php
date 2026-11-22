<?php

declare(strict_types=1);

use App\Models\ServiceConnection;

/**
 * Returns every element id that appears more than once on the page.
 */
function loginFormDuplicateIdsScript(): string
{
    return <<<'JS_WRAP'
        (() => {
            const counts = new Map();
            document.querySelectorAll('[id]').forEach((element) => counts.set(element.id, (counts.get(element.id) ?? 0) + 1));
            return JSON.stringify([...counts].filter(([, count]) => count > 1).map(([id]) => id));
        })()
    JS_WRAP;
}

/**
 * Returns the text of every `label[for]` whose target is missing or is not a
 * labelable element, and of every label whose control is not the one element
 * carrying its `for` id.
 */
function loginFormUnlinkedLabelsScript(): string
{
    return <<<'JS_WRAP'
        JSON.stringify([...document.querySelectorAll('label[for]')]
            .filter((label) => label.control === null || label.control !== document.getElementById(label.htmlFor))
            .map((label) => label.textContent.trim()))
    JS_WRAP;
}

test('the email-only login page has unique ids and every label names its input', function (): void {
    config()->set('services.authentik.client_id');

    visit(route('login', absolute: false))
        ->assertNoSmoke()
        ->assertPresent('[data-test="login-button"]')
        ->assertScript(loginFormDuplicateIdsScript(), '[]')
        ->assertScript(loginFormUnlinkedLabelsScript(), '[]')
        ->assertScript('document.querySelectorAll("label[for]").length >= 3', true);
});

test('the login page with Emby and the email form both open has unique ids and every label names its input', function (): void {
    config()->set('services.authentik.client_id', 'example-client');
    ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);

    $webpage = visit(route('login', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-login-emby]')
        ->click('[data-login-local-toggle]')
        ->assertVisible('[data-test="login-button"]')
        ->assertVisible('[data-login-emby-form]')
        ->assertScript(loginFormDuplicateIdsScript(), '[]')
        ->assertScript(loginFormUnlinkedLabelsScript(), '[]')
        ->assertScript('document.querySelectorAll("label[for]").length >= 6', true);
});
