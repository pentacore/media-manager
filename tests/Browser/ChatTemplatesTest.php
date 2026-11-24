<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use App\Models\ChatTemplate;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Str;

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

test('on a phone a template row keeps its name clear of the last-used text and its actions', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->create(['name' => 'Example officiis template', 'last_used_at' => null]);
    $this->actingAs($admin);

    // The name, the "Never used" text and the Pin/Edit/Delete buttons share
    // one line at desktop; at 375 px the name must not run under the others.
    $row = sprintf('[data-template-row="%d"]', $chatTemplate->id);
    $boxes = json_encode([$row.' > div:first-child span', $row.' > span', $row.' > div:last-child'], JSON_THROW_ON_ERROR);

    visit(route('ai.templates.index', absolute: false))->on()->mobile()
        ->assertNoSmoke()
        ->assertSeeIn($row.' > span', 'Never used')
        ->assertScript("(() => { const rects = {$boxes}.map((selector) => document.querySelector(selector).getBoundingClientRect()); const overlap = (a, b) => a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom; return !overlap(rects[0], rects[1]) && !overlap(rects[0], rects[2]) && !overlap(rects[1], rects[2]); })()", true)
        ->assertScript(phoneOverflowScript($row), '');
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

test('while a workflow proposal is pending a template only fills the composer and the model picker is locked', function (): void {
    $admin = User::factory()->admin()->create();
    MediaAgent::fake(function () use ($admin): string {
        AiProposedWorkflow::create([
            'id' => (string) Str::uuid7(),
            'user_id' => $admin->id,
            'conversation_id' => null,
            'rationale' => 'Cleaning up unwatched series',
            'steps' => [['action' => 'delete_series', 'target' => 'Demo Show', 'reason' => 'Unwatched 8mo']],
            'status' => AiProposedWorkflowStatus::Proposed,
        ]);

        return 'I have proposed a workflow.';
    });
    $chatTemplate = ChatTemplate::factory()->for($admin)->autoSend()->withBody('Find {{lang}} subtitles', [
        ['name' => 'lang', 'label' => 'Language', 'type' => 'text', 'default' => 'Swedish', 'options' => null],
    ])->create(['model' => 'gpt-5-nano', 'model_provider' => 'openai']);
    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('[data-chat-input]', 'Clean up my old shows please.')
        ->click('Send')
        ->assertSee('Proposed workflow')
        ->assertScript("document.querySelector('[data-chat-model-chip]').disabled", true)
        ->click('[data-template-picker]')
        ->click(sprintf('[data-template-option="%d"]', $chatTemplate->id))
        ->assertVisible('[data-template-fill-dialog]')
        ->assertMissing('[data-template-preset]')
        ->assertScript("document.querySelector('[data-template-send]').disabled", true)
        ->click('[data-template-insert]')
        ->assertValue('[data-chat-input]', 'Find Swedish subtitles')
        ->assertDontSeeIn('[data-chat-thread]', 'Find Swedish subtitles')
        ->assertScript("document.querySelector('[data-chat-model-chip]').disabled", true);
});

/**
 * Fake an agent that proposes a workflow for the first message, acknowledges
 * a decline and answers the stuck-downloads template. Every prompt the agent
 * sees is collected, whatever asked for it.
 *
 * @param  array<int, string>  $prompts
 */
function fakeChatWithWorkflowProposal(User $user, array &$prompts): void
{
    MediaAgent::fake(function (string $prompt) use ($user, &$prompts): string {
        $prompts[] = $prompt;

        return match (true) {
            str_contains($prompt, 'DECLINED') => 'Understood, what next?',
            str_contains($prompt, 'stuck downloads') => 'Nothing is stuck.',
            default => (function () use ($user): string {
                AiProposedWorkflow::query()->firstOrCreate(['user_id' => $user->id], [
                    'id' => (string) Str::uuid7(),
                    'conversation_id' => null,
                    'rationale' => 'Cleaning up unwatched series',
                    'steps' => [['action' => 'delete_series', 'target' => 'Demo Show', 'reason' => 'Unwatched 8mo']],
                    'status' => AiProposedWorkflowStatus::Proposed,
                ]);

                return 'I have proposed a workflow.';
            })(),
        };
    });
}

test('while a workflow proposal is pending an auto-send template without variables fills the composer and sends nothing', function (): void {
    $admin = User::factory()->admin()->create();
    $prompts = [];
    fakeChatWithWorkflowProposal($admin, $prompts);
    $chatTemplate = ChatTemplate::factory()->for($admin)->autoSend()->create([
        'name' => 'Stuck downloads',
        'body' => 'Check Sonarr and Radarr for stuck downloads',
    ]);
    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('[data-chat-input]', 'Clean up my old shows please.')
        ->click('Send')
        ->assertSee('Proposed workflow')
        ->click('[data-template-picker]')
        ->click(sprintf('[data-template-option="%d"]', $chatTemplate->id))
        ->assertValue('[data-chat-input]', 'Check Sonarr and Radarr for stuck downloads')
        ->assertDontSeeIn('[data-chat-thread]', 'Check Sonarr and Radarr for stuck downloads')
        ->assertScript("document.querySelector('[data-chat-model-chip]').disabled", true);

    expect(collect($prompts)->filter(fn (string $prompt): bool => str_contains($prompt, 'stuck downloads')))->toBeEmpty();
});

test('once the proposal is declined the model picker is enabled and templates send normally', function (): void {
    $admin = User::factory()->admin()->create();
    $prompts = [];
    fakeChatWithWorkflowProposal($admin, $prompts);
    $chatTemplate = ChatTemplate::factory()->for($admin)->autoSend()->create([
        'name' => 'Stuck downloads',
        'body' => 'Check Sonarr and Radarr for stuck downloads',
    ]);
    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('[data-chat-input]', 'Clean up my old shows please.')
        ->click('Send')
        ->assertSee('Proposed workflow')
        ->assertScript("document.querySelector('[data-chat-model-chip]').disabled", true)
        ->click('Decline')
        ->assertSee('Understood, what next?')
        ->assertScript("document.querySelector('[data-chat-model-chip]').disabled", false)
        ->click('[data-template-picker]')
        ->click(sprintf('[data-template-option="%d"]', $chatTemplate->id))
        ->assertSeeIn('[data-chat-thread]', 'Check Sonarr and Radarr for stuck downloads')
        ->assertSee('Nothing is stuck.');

    expect(collect($prompts)->filter(fn (string $prompt): bool => str_contains($prompt, 'stuck downloads')))->toHaveCount(1);
});

test('the fill dialog names the preset model in a new chat', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->withBody('Find {{lang}} subtitles', [
        ['name' => 'lang', 'label' => 'Language', 'type' => 'text', 'default' => 'Swedish', 'options' => null],
    ])->create(['model' => 'gpt-5-nano', 'model_provider' => 'openai']);
    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->click('[data-template-picker]')
        ->click(sprintf('[data-template-option="%d"]', $chatTemplate->id))
        ->assertVisible('[data-template-fill-dialog]')
        ->assertSeeIn('[data-template-preset]', 'Uses gpt-5-nano');
});

test('the fill dialog leaves out the preset model in an existing conversation', function (): void {
    MediaAgent::fake(['Sure.']);
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->withBody('Find {{lang}} subtitles', [
        ['name' => 'lang', 'label' => 'Language', 'type' => 'text', 'default' => 'Swedish', 'options' => null],
    ])->create(['model' => 'gpt-5-nano', 'model_provider' => 'openai']);
    $this->actingAs($admin);

    visit('/ai/chat')
        ->assertNoSmoke()
        ->type('[data-chat-input]', 'Check the anime queue')
        ->click('Send')
        ->assertSee('Sure.')
        ->click('[data-template-picker]')
        ->click(sprintf('[data-template-option="%d"]', $chatTemplate->id))
        ->assertVisible('[data-template-fill-dialog]')
        ->assertMissing('[data-template-preset]');
});

test('the template editor saves tool groups', function (): void {
    ServiceConnection::factory()->prowlarr()->create();
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->create(['name' => 'Indexer check']);
    $this->actingAs($admin);

    visit(route('ai.templates.edit', $chatTemplate, absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-template-tool-group="indexers"]')
        ->click('[data-template-tool-group="indexers"]')
        ->click('[data-template-save]')
        ->assertSee('Template updated.');

    expect($chatTemplate->fresh()->tool_groups)->toBe(['indexers']);

    visit(route('ai.templates.edit', $chatTemplate, absolute: false))
        ->assertNoSmoke()
        ->assertAttribute('[data-template-tool-group="indexers"]', 'data-state', 'checked');
});

test("a template's tool groups are pinned when it is used", function (): void {
    resolve(AiSettings::class)->setChatRoutingEnabled(true);
    ServiceConnection::factory()->prowlarr()->create();
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->create(['name' => 'Indexer check', 'tool_groups' => ['indexers']]);
    $this->actingAs($admin);

    $webpage = visit('/ai/chat')->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-template-picker]')
        ->click(sprintf('[data-template-option="%d"]', $chatTemplate->id))
        ->assertPresent('[data-tool-chip="indexers"][data-tool-source="pinned"]');
});

test('save as template carries a long message in the request body', function (): void {
    MediaAgent::fake(['Sure.']);
    $this->actingAs(User::factory()->admin()->create());
    // ~3,900 characters: well past what a ?body= query survives behind a
    // small proxy buffer (the old 414), and still under the 4,000 cap.
    $message = rtrim(str_repeat('Check the anime queue for stuck downloads. ', 90));

    $webpage = visit('/ai/chat')->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->fill('[data-chat-input]', $message)
        ->click('Send')
        ->assertSee('Sure.')
        ->click('[data-save-as-template]')
        ->assertValue('[data-template-body]', $message)
        ->assertPathIs('/ai/templates/create');
});

test('double-clicking a chip inserts its template once', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->pinned()->create([
        'name' => 'Queue check',
        'body' => 'Check the queue',
        'auto_send' => false,
    ]);
    $this->actingAs($admin);

    $chip = sprintf('[data-template-chip="%d"]', $chatTemplate->id);

    $webpage = visit('/ai/chat')->assertNoSmoke()->assertVisible($chip);
    $webpage->script(appHydratedScript());
    // Two clicks before the first render answers, as a double-click lands.
    $webpage->script(sprintf('(() => { const chip = document.querySelector(%s); chip.click(); chip.click(); })()', json_encode($chip)));
    $webpage->script(confirmDialogWaitUntilScript('document.querySelector("[data-chat-input]").value !== ""'));
    // Give a second render time to land before checking it never did.
    $webpage->wait(1);
    $webpage->assertValue('[data-chat-input]', 'Check the queue');
});

test('double-clicking insert in the fill dialog inserts once', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->withBody('Find {{what}}', [
        ['name' => 'what', 'label' => 'What', 'type' => 'text', 'default' => null, 'options' => null],
    ])->create();
    $this->actingAs($admin);

    $webpage = visit('/ai/chat')->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-template-picker]')
        ->click(sprintf('[data-template-option="%d"]', $chatTemplate->id))
        ->fill('[data-template-field="what"]', 'Dune');
    $webpage->script('(() => { const insert = document.querySelector("[data-template-insert]"); insert.click(); insert.click(); })()');
    $webpage->script(confirmDialogWaitUntilScript('document.querySelector("[data-chat-input]").value !== ""'));
    $webpage->wait(1);
    $webpage->assertValue('[data-chat-input]', 'Find Dune')
        ->assertMissing('[data-template-fill-dialog]');
});

test('a template that cannot reach the server shows friendly copy, not the browser error', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->pinned()->create([
        'name' => 'Queue check',
        'body' => 'Check the queue',
        'auto_send' => false,
    ]);
    $this->actingAs($admin);

    $chip = sprintf('[data-template-chip="%d"]', $chatTemplate->id);

    $webpage = visit('/ai/chat')->assertNoSmoke()->assertVisible($chip);
    $webpage->script(appHydratedScript());
    // The render request fails the way a dropped connection does.
    $webpage->script(<<<'JS'
        (() => {
            const original = window.fetch.bind(window);
            window.fetch = (input, init) => String(input).endsWith('/render')
                ? Promise.reject(new TypeError('Failed to fetch'))
                : original(input, init);
        })()
        JS);
    $webpage->click($chip)
        ->assertSee('Could not reach the server. Try again.')
        ->assertDontSee('Failed to fetch')
        ->assertValue('[data-chat-input]', '');
});

test('an older template list refresh that answers last does not replace a newer one', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->create(['name' => 'Queue check']);
    $this->actingAs($admin);

    $webpage = visit('/ai/chat')->assertNoSmoke();
    $webpage->script(appHydratedScript());
    // The first refresh from here on answers late with an empty list; the
    // second answers at once with the real one.
    $webpage->script(<<<'JS_WRAP'
    (() => {
        const original = window.fetch.bind(window);
        let calls = 0;
        window.fetch = (input, init) => {
            if (!String(input).includes('/ai/templates/options')) {
                return original(input, init);
            }
            calls += 1;
            if (calls === 1) {
                return new Promise((resolve) => setTimeout(() => resolve(new Response(
                    JSON.stringify({ templates: [] }),
                    { status: 200, headers: { 'Content-Type': 'application/json' } },
                )), 800));
            }
            return original(input, init);
        };
    })()
    JS_WRAP);
    $webpage->click('[data-template-picker]')
        ->assertVisible('[data-template-picker-panel]')
        ->keys('[data-template-filter]', 'Escape')
        ->assertMissing('[data-template-picker-panel]')
        ->click('[data-template-picker]')
        ->assertVisible(sprintf('[data-template-option="%d"]', $chatTemplate->id));
    // Let the stale answer land, then check it changed nothing.
    $webpage->wait(1.5);
    $webpage->assertVisible(sprintf('[data-template-option="%d"]', $chatTemplate->id))
        ->assertDontSeeIn('[data-template-options]', 'No templates yet.');
});

test('a failed template list refresh keeps the list already shown', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->create(['name' => 'Queue check']);
    $this->actingAs($admin);

    $option = sprintf('[data-template-option="%d"]', $chatTemplate->id);

    $webpage = visit('/ai/chat')->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-template-picker]')
        ->assertVisible($option)
        ->keys('[data-template-filter]', 'Escape')
        ->assertMissing('[data-template-picker-panel]');
    $webpage->script(<<<'JS'
        (() => {
            const original = window.fetch.bind(window);
            window.fetch = (input, init) => String(input).includes('/ai/templates/options')
                ? Promise.reject(new TypeError('Failed to fetch'))
                : original(input, init);
        })()
        JS);
    $webpage->click('[data-template-picker]')
        ->assertVisible($option);
    $webpage->wait(0.5);
    $webpage->assertVisible($option)
        ->assertMissing('[data-template-load-failed]');
});

test('the template picker, its list and its filter have accessible names', function (): void {
    $admin = User::factory()->admin()->create();
    ChatTemplate::factory()->for($admin)->subtitleCheck()->create();
    $this->actingAs($admin);

    $webpage = visit('/ai/chat')->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertAttribute('[data-template-picker]', 'aria-label', 'Templates')
        ->click('[data-template-picker]')
        ->assertAttribute('[data-template-picker-panel]', 'role', 'dialog')
        ->assertAttribute('[data-template-picker-panel]', 'aria-label', 'Templates')
        ->assertAttribute('[data-template-options]', 'role', 'group')
        ->assertAttribute('[data-template-options]', 'aria-label', 'Saved templates')
        ->assertAttribute('[data-template-filter]', 'aria-label', 'Filter templates');
});

test('the fill dialog names a library search box after its variable', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->subtitleCheck()->create();
    $this->actingAs($admin);

    $label = $chatTemplate->variables[0]['label'];

    $webpage = visit('/ai/chat')->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-template-picker]')
        ->click(sprintf('[data-template-option="%d"]', $chatTemplate->id))
        ->assertAttribute('[data-library-search]', 'aria-label', $label);
});
