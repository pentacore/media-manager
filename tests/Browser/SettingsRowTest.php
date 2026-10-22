<?php

declare(strict_types=1);

use App\Models\User;

test('profile settings rows stack the label above a full-width control on a phone', function (): void {
    $this->actingAs(User::factory()->member()->create(['name' => 'Example Member']));

    $webpage = visit(route('profile.edit', absolute: false))->on()->mobile()->assertNoSmoke();

    $webpage->assertScript('document.querySelectorAll("[data-settings-row]").length >= 2', true)
        ->assertScript('[...document.querySelectorAll("[data-settings-row]")].every((row) => { const [label, control] = row.children; return label.getBoundingClientRect().bottom <= control.getBoundingClientRect().top + 1; })', true)
        ->assertScript('document.querySelector("#name").getBoundingClientRect().width >= 280', true)
        ->assertScript(phoneOverflowScript('main'), '');
});

test('preferences, AI settings, decision agent and media replacement rows fit a phone', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('settings.preferences.edit', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->assertScript(phoneOverflowScript('[data-settings-row]'), '');

    foreach (['admin.ai-settings.index', 'admin.decision-agent.index', 'admin.media-replacement.index'] as $routeName) {
        $webpage->navigate(route($routeName, absolute: false));
        $webpage->assertPresent('[data-settings-row]')
            ->assertScript(phoneOverflowScript('[data-settings-row]'), '');
    }
});

test('at desktop width a settings row keeps its 200 px label column', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('profile.edit', absolute: false))
        ->assertNoSmoke()
        ->assertScript('(() => { const row = document.querySelector("[data-settings-row]"); const [label, control] = row.children; return Math.round(label.getBoundingClientRect().width) === 200 && Math.round(control.getBoundingClientRect().left - label.getBoundingClientRect().left) === 224; })()', true);
});
