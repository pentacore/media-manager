<?php

declare(strict_types=1);

use App\Models\ChatTemplate;
use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

test('an admin creates a template with live variable rows and preview', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->fill('[data-template-name]', 'Subtitle check')
        ->fill('[data-template-body]', 'Check {{anime:title,year}} S{{season}}E{{episode}}')
        ->assertVisible('[data-variable-row="anime"]')
        ->assertVisible('[data-variable-row="season"]')
        ->assertVisible('[data-variable-row="episode"]')
        ->select('[data-variable-row="anime"] [data-variable-type]', 'series')
        ->select('[data-variable-row="season"] [data-variable-type]', 'number')
        ->select('[data-variable-row="episode"] [data-variable-type]', 'number')
        ->assertSeeIn('[data-template-preview]', '[Anime: title, year]')
        ->click('[data-template-save]')
        ->assertSee('Template saved.')
        ->assertSeeIn('[data-template-row]', 'Subtitle check');

    expect($admin->chatTemplates()->sole()->variables[0]['type'])->toBe('series');
});

test('variable settings survive removing and re-adding a token', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->fill('[data-template-body]', 'Subtitles in {{lang}}')
        ->select('[data-variable-row="lang"] [data-variable-type]', 'choice')
        ->fill('[data-variable-row="lang"] [data-variable-options]', "English\nSwedish")
        ->fill('[data-template-body]', 'Subtitles')
        ->assertMissing('[data-variable-row="lang"]')
        ->fill('[data-template-body]', 'Subtitles in {{lang}}')
        ->assertValue('[data-variable-row="lang"] [data-variable-type]', 'choice')
        ->assertValue('[data-variable-row="lang"] [data-variable-options]', "English\nSwedish");
});

test('grammar errors show in the live preview and on save', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->fill('[data-template-name]', 'Broken')
        ->fill('[data-template-body]', 'Check {{anime:poster}}')
        ->assertSeeIn('[data-template-preview-errors]', 'unknown part')
        ->click('[data-template-save]')
        ->assertSeeIn('[data-template-body-error]', 'unknown part');

    expect(ChatTemplate::query()->count())->toBe(0);
});

test('the list shows templates and pins them', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->create(['name' => 'Stuck downloads']);
    $this->actingAs($admin);

    visit(route('ai.templates.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-template-row="%d"]', $chatTemplate->id), 'Stuck downloads')
        ->click(sprintf('[data-template-row="%d"] [data-template-pin]', $chatTemplate->id))
        ->assertSee('Template pinned.');

    expect($chatTemplate->fresh()->pinned)->toBeTrue();
});

test('the empty list explains the placeholder syntax', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.templates.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-template-empty]', '{{name:title,year,id}}');
});
