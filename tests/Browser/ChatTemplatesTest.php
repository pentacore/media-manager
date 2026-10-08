<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\ChatTemplate;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
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
        ->assertSeeIn('[data-template-preview]', 'The Show (2020)')
        ->click('[data-template-save]')
        ->assertSee('Template saved.')
        ->assertSeeIn('[data-template-row]', 'Subtitle check');

    expect($admin->chatTemplates()->sole()->variables[0]['type'])->toBe('series');
});

test('an admin opens an existing template in the editor', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->subtitleCheck()->create(['name' => 'Subtitle check']);
    $this->actingAs($admin);

    visit(route('ai.templates.edit', $chatTemplate, absolute: false))
        ->assertNoSmoke()
        ->assertValue('[data-template-name]', 'Subtitle check')
        ->assertVisible('[data-variable-row="anime"]');
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

test('the help panel is open on a new template and remembers being closed', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-template-help]', 'How templates work')
        ->assertSeeIn('[data-template-help]', 'The assistant gets')
        ->click('[data-template-help-toggle]')
        ->assertDontSeeIn('[data-template-help]', 'The assistant gets')
        ->refresh()
        ->assertSeeIn('[data-template-help]', 'How templates work')
        ->assertDontSeeIn('[data-template-help]', 'The assistant gets');
});

test('the help panel starts collapsed when editing and opens on demand', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->subtitleCheck()->create();
    $this->actingAs($admin);

    visit(route('ai.templates.edit', $chatTemplate, absolute: false))
        ->assertNoSmoke()
        ->assertDontSeeIn('[data-template-help]', 'The assistant gets')
        ->click('[data-template-help-toggle]')
        ->assertSeeIn('[data-template-help]', 'The assistant gets');
});

test('the ledger inserts an existing variable at the message cursor', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->fill('[data-template-body]', 'Hello {{season}} world')
        ->assertSeeIn('[data-ledger-variable="season"]', 'Text');

    $webpage->script(chatTemplatesPlaceCaretScript(6, 6));

    $webpage->click('[data-ledger-variable="season"] [data-ledger-insert]')
        ->assertValue('[data-template-body]', 'Hello {{season}}{{season}} world');

    expect($webpage->script(chatTemplatesCaretScript()))->toBe(['focused' => true, 'caret' => 16]);
});

test('the ledger replaces the selected text in the message', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->fill('[data-template-body]', 'Season X of {{season}}')
        ->assertVisible('[data-ledger-variable="season"]');

    $webpage->script(chatTemplatesPlaceCaretScript(7, 8));

    $webpage->click('[data-ledger-variable="season"] [data-ledger-insert]')
        ->assertValue('[data-template-body]', 'Season {{season}} of {{season}}');

    expect($webpage->script(chatTemplatesCaretScript()))->toBe(['focused' => true, 'caret' => 17]);
});

test('the ledger appends when the message was never focused', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->withBody('Check {{what}}', [
        ['name' => 'what', 'label' => 'What', 'type' => 'text', 'default' => null, 'options' => null],
    ])->create();
    $this->actingAs($admin);

    visit(route('ai.templates.edit', $chatTemplate, absolute: false))
        ->assertNoSmoke()
        ->click('[data-ledger-variable="what"] [data-ledger-insert]')
        ->assertValue('[data-template-body]', 'Check {{what}}{{what}}');
});

test('series part chips insert the parts in click order and reset', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->fill('[data-template-body]', 'Check {{anime}} ')
        ->select('[data-variable-row="anime"] [data-variable-type]', 'series')
        ->click('[data-ledger-variable="anime"] [data-ledger-part="title"]')
        ->click('[data-ledger-variable="anime"] [data-ledger-part="year"]')
        ->click('[data-ledger-variable="anime"] [data-ledger-insert]')
        ->assertValue('[data-template-body]', 'Check {{anime}} {{anime:title,year}}')
        ->assertAttribute('[data-ledger-variable="anime"] [data-ledger-part="title"]', 'aria-pressed', 'false');
});

test('chosen parts are dropped when a variable stops being a series', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->fill('[data-template-body]', 'Check {{anime}} ')
        ->select('[data-variable-row="anime"] [data-variable-type]', 'series')
        ->click('[data-ledger-variable="anime"] [data-ledger-part="year"]')
        ->select('[data-variable-row="anime"] [data-variable-type]', 'text')
        ->assertMissing('[data-ledger-variable="anime"] [data-ledger-part="year"]')
        ->click('[data-ledger-variable="anime"] [data-ledger-insert]')
        ->assertValue('[data-template-body]', 'Check {{anime}} {{anime}}')
        ->select('[data-variable-row="anime"] [data-variable-type]', 'series')
        ->assertAttribute('[data-ledger-variable="anime"] [data-ledger-part="year"]', 'aria-pressed', 'false');
});

test('adding a series variable inserts its token with the series type pre-set', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->fill('[data-template-body]', 'Check ')
        ->click('[data-ledger-add="series"]')
        ->assertValue('[data-ledger-new-name]', 'anime')
        ->click('[data-ledger-new-insert]')
        ->assertValue('[data-template-body]', 'Check {{anime}}')
        ->assertValue('[data-variable-row="anime"] [data-variable-type]', 'series');
});

test('a new variable name that is taken shows an error and inserts nothing', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->fill('[data-template-body]', 'Check {{anime}}')
        ->click('[data-ledger-add="series"]')
        ->assertValue('[data-ledger-new-name]', 'series')
        ->fill('[data-ledger-new-name]', 'anime')
        ->assertSeeIn('[data-ledger-new-error]', 'already has a variable')
        ->assertDisabled('[data-ledger-new-insert]')
        ->keys('[data-ledger-new-name]', 'Enter')
        ->assertValue('[data-template-body]', 'Check {{anime}}')
        ->assertVisible('[data-ledger-new-error]');
});

test('a taken suggestion gets a numbered name', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->fill('[data-template-body]', 'Check {{text}} ')
        ->click('[data-ledger-add="text"]')
        ->assertValue('[data-ledger-new-name]', 'text_2')
        ->click('[data-ledger-new-insert]')
        ->assertValue('[data-template-body]', 'Check {{text}} {{text_2}}')
        ->assertVisible('[data-variable-row="text_2"]');
});

test('enter in the new variable name inserts a choice variable', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->fill('[data-template-body]', 'Subtitles in ')
        ->click('[data-ledger-add="choice"]')
        ->fill('[data-ledger-new-name]', 'lang')
        ->keys('[data-ledger-new-name]', 'Enter')
        ->assertValue('[data-template-body]', 'Subtitles in {{lang}}')
        ->assertValue('[data-variable-row="lang"] [data-variable-type]', 'choice')
        ->assertVisible('[data-variable-row="lang"] [data-variable-options]');
});

test('the preview switches between example values and placeholder names', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->fill('[data-template-body]', 'Check {{anime}}')
        ->select('[data-variable-row="anime"] [data-variable-type]', 'series')
        ->assertSeeIn('[data-template-preview]', 'The Show')
        ->click('[data-preview-mode="names"]')
        ->assertSeeIn('[data-template-preview]', '[Anime')
        ->refresh()
        ->assertAttribute('[data-preview-mode="names"]', 'aria-pressed', 'true')
        ->fill('[data-template-body]', 'Check {{anime}}')
        ->select('[data-variable-row="anime"] [data-variable-type]', 'series')
        ->assertSeeIn('[data-template-preview]', '[Anime')
        ->click('[data-preview-mode="example"]')
        ->assertSeeIn('[data-template-preview]', 'The Show');
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
    $chatTemplate = ChatTemplate::factory()->for($admin)->create(['name' => 'Stuck downloads', 'body' => 'Look for anything stalled in the queues']);
    $this->actingAs($admin);

    visit(route('ai.templates.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-template-row="%d"]', $chatTemplate->id), 'Stuck downloads')
        ->click(sprintf('[data-template-row="%d"] [data-template-pin]', $chatTemplate->id))
        ->assertSee('Template pinned.');

    expect($chatTemplate->fresh()->pinned)->toBeTrue();
});

test('the empty list explains the placeholder syntax and links to the editor', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.templates.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-template-empty]', '{{name:title,year,id}}')
        ->assertSeeIn('[data-template-empty]', 'Check Frieren (2023) S1E7 for subtitles')
        ->click('[data-template-create-first]')
        ->assertSeeIn('[data-template-help]', 'How templates work');
});

test('a pinned auto-send template sends from the empty-state chip', function (): void {
    MediaAgent::fake(['On it.']);
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->pinned()->autoSend()->create([
        'name' => 'Stuck downloads',
        'body' => 'Check Sonarr and Radarr for stuck downloads',
    ]);
    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->click(sprintf('[data-template-chip="%d"]', $chatTemplate->id))
        ->assertSeeIn('[data-chat-thread]', 'Check Sonarr and Radarr for stuck downloads')
        ->assertSee('On it.');

    expect($chatTemplate->fresh()->last_used_at)->not->toBeNull();
});

test('without pinned templates the empty state links to the template page', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit('/ai/chat')
        ->assertNoSmoke()
        ->assertSeeIn('[data-template-hint]', 'Save prompts you reuse as templates');
});

test('the picker fills a series template into the composer without sending', function (): void {
    MediaAgent::fake(['should not be used']);
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    IndexedSeries::factory()->create(['service_connection_id' => $sonarr->id, 'sonarr_id' => 42, 'title' => 'Frieren', 'year' => 2023]);
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->subtitleCheck()->create();
    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->click('[data-template-picker]')
        ->click(sprintf('[data-template-option="%d"]', $chatTemplate->id))
        ->assertVisible('[data-template-fill-dialog]')
        ->type('[data-library-search]', 'frie')
        ->click('[data-library-option="42"]')
        ->assertSeeIn('[data-library-selected]', 'Frieren')
        ->fill('[data-template-field="season"]', '1')
        ->fill('[data-template-field="episode"]', '7')
        ->click('[data-template-insert]')
        ->assertValue('[data-chat-input]', 'Check Frieren (2023) S1E7 for subtitles')
        ->assertDontSeeIn('[data-chat-thread]', 'should not be used');
});

test('send from the fill dialog sends the rendered message', function (): void {
    MediaAgent::fake(['Looking into Swedish subtitles.']);
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->withBody('Find {{lang}} subtitles for {{what}}', [
        ['name' => 'lang', 'label' => 'Language', 'type' => 'choice', 'default' => 'Swedish', 'options' => ['English', 'Swedish']],
        ['name' => 'what', 'label' => 'What', 'type' => 'text', 'default' => null, 'options' => null],
    ])->create();
    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->click('[data-template-picker]')
        ->click(sprintf('[data-template-option="%d"]', $chatTemplate->id))
        ->fill('[data-template-field="what"]', 'Dune')
        ->click('[data-template-send]')
        ->assertSeeIn('[data-chat-thread]', 'Find Swedish subtitles for Dune')
        ->assertSee('Looking into Swedish subtitles.');
});

test('save as template opens the editor with the sent message', function (): void {
    MediaAgent::fake(['Sure.']);
    $this->actingAs(User::factory()->admin()->create());

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('[data-chat-input]', 'Check the anime queue')
        ->click('Send')
        ->assertSee('Sure.')
        ->click('[data-save-as-template]')
        ->assertValue('[data-template-body]', 'Check the anime queue');
});

test('enter in a fill dialog field runs the primary action', function (): void {
    MediaAgent::fake(['should not be used']);
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    IndexedSeries::factory()->create(['service_connection_id' => $sonarr->id, 'sonarr_id' => 42, 'title' => 'Frieren', 'year' => 2023]);
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->subtitleCheck()->create();
    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->click('[data-template-picker]')
        ->click(sprintf('[data-template-option="%d"]', $chatTemplate->id))
        ->type('[data-library-search]', 'frie')
        ->click('[data-library-option="42"]')
        ->assertSeeIn('[data-library-selected]', 'Frieren')
        ->fill('[data-template-field="season"]', '1')
        ->fill('[data-template-field="episode"]', '7')
        ->keys('[data-template-field="episode"]', 'Enter')
        ->assertValue('[data-chat-input]', 'Check Frieren (2023) S1E7 for subtitles')
        ->assertDontSeeIn('[data-chat-thread]', 'should not be used');
});

test('the fill dialog explains a template that changed after it opened', function (): void {
    MediaAgent::fake(['should not be used']);
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->withBody('Find subtitles for {{what}}', [
        ['name' => 'what', 'label' => 'What', 'type' => 'text', 'default' => null, 'options' => null],
    ])->create();
    $this->actingAs($admin);

    $webpage = visit('/ai/chat')
        ->assertNoSmoke()
        ->click('[data-template-picker]')
        ->click(sprintf('[data-template-option="%d"]', $chatTemplate->id))
        ->assertVisible('[data-template-fill-dialog]');

    $chatTemplate->update([
        'body' => 'Find {{lang}} subtitles for {{what}}',
        'variables' => [
            ['name' => 'lang', 'label' => 'Language', 'type' => 'text', 'default' => null, 'options' => null],
            ...$chatTemplate->variables,
        ],
    ]);

    $webpage->fill('[data-template-field="what"]', 'Dune')
        ->click('[data-template-insert]')
        ->assertSeeIn('[data-template-fill-error]', 'Language')
        ->assertValue('[data-chat-input]', '');
});

/**
 * Focuses the message box and selects [start, end); equal offsets place the caret.
 */
function chatTemplatesPlaceCaretScript(int $start, int $end): string
{
    return sprintf("(() => { const el = document.querySelector('[data-template-body]'); el.focus(); el.setSelectionRange(%d, %d); })()", $start, $end);
}

/**
 * Whether the message box has focus, and where its caret is.
 */
function chatTemplatesCaretScript(): string
{
    return "(() => { const el = document.querySelector('[data-template-body]'); return { focused: document.activeElement === el, caret: el.selectionStart }; })()";
}

test('the editor offers a model preset and saves the chosen reasoning level', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    visit(route('ai.templates.create', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-template-preset]')
        ->fill('[data-template-name]', 'Deep thinker')
        ->fill('[data-template-body]', 'Think hard about this')
        ->click('[data-template-preset] [data-reasoning-select] button')
        ->click('[data-reasoning-option="high"]')
        ->click('[data-template-save]')
        ->assertSee('Template saved.');

    expect($admin->chatTemplates()->sole()->reasoning?->value)->toBe('high');
});
