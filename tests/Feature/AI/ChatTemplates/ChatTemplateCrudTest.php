<?php

declare(strict_types=1);

use App\Models\ChatTemplate;
use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chatTemplatePayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Subtitle check',
        'body' => 'Check {{anime:title,year}} S{{season}}E{{episode}} for subtitles',
        'variables' => [
            ['name' => 'anime', 'label' => 'Anime', 'type' => 'series', 'default' => null, 'options' => null],
            ['name' => 'season', 'label' => 'Season', 'type' => 'number', 'default' => '1', 'options' => null],
            ['name' => 'episode', 'label' => 'Episode', 'type' => 'number', 'default' => null, 'options' => null],
        ],
        'auto_send' => false,
        'pinned' => true,
    ], $overrides);
}

test("the index lists only the current user's templates, pinned first", function (): void {
    $admin = User::factory()->admin()->create();
    ChatTemplate::factory()->for($admin)->create(['name' => 'Alpha']);
    ChatTemplate::factory()->for($admin)->pinned()->create(['name' => 'Zulu']);
    ChatTemplate::factory()->create(['name' => 'Someone else']);

    $this->actingAs($admin)
        ->get(route('ai.templates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('AI/Templates/Index')
            ->has('templates', 2)
            ->where('templates.0.name', 'Zulu')
            ->where('templates.1.name', 'Alpha'));
});

test('the create page pre-fills the body from the query, capped at 4000 characters', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('ai.templates.create', ['body' => str_repeat('a', 4100)]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('AI/Templates/Edit')
            ->where('template', null)
            ->where('prefillBody', str_repeat('a', 4000))
            ->has('variableTypes', 5));
});

test('storing a template normalises its variables and redirects with a toast', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('ai.templates.store'), chatTemplatePayload([
            'body' => 'Subtitles in {{lang}} for {{anime}}',
            'variables' => [
                ['name' => 'lang', 'label' => 'Language', 'type' => 'choice', 'default' => 'English', 'options' => [' English ', 'Swedish', '', 'English']],
                ['name' => 'anime', 'label' => 'Anime', 'type' => 'series', 'default' => null, 'options' => null],
            ],
        ]))
        ->assertRedirect(route('ai.templates.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Template saved.');

    $chatTemplate = $admin->chatTemplates()->sole();

    expect($chatTemplate->name)->toBe('Subtitle check')
        ->and($chatTemplate->pinned)->toBeTrue()
        ->and($chatTemplate->variables)->toBe([
            ['name' => 'lang', 'label' => 'Language', 'type' => 'choice', 'default' => 'English', 'options' => ['English', 'Swedish']],
            ['name' => 'anime', 'label' => 'Anime', 'type' => 'series', 'default' => null, 'options' => null],
        ]);
});

test('invalid definitions are rejected on the offending field', function (array $overrides, string $field): void {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('ai.templates.store'), chatTemplatePayload($overrides))
        ->assertSessionHasErrors($field);

    expect(ChatTemplate::query()->count())->toBe(0);
})->with([
    'malformed token' => [['body' => 'Check {{ anime }}', 'variables' => []], 'body'],
    'unknown part' => [['body' => '{{anime:poster}}', 'variables' => [['name' => 'anime', 'label' => 'Anime', 'type' => 'series']]], 'body'],
    'parts on a text variable' => [['body' => '{{q:title}}', 'variables' => [['name' => 'q', 'label' => 'Q', 'type' => 'text']]], 'body'],
    'token without settings' => [['body' => '{{q}}', 'variables' => []], 'body'],
    'unused variable' => [['body' => 'Hello', 'variables' => [['name' => 'q', 'label' => 'Q', 'type' => 'text']]], 'variables.0.name'],
    'options on text' => [['body' => '{{q}}', 'variables' => [['name' => 'q', 'label' => 'Q', 'type' => 'text', 'options' => ['a', 'b']]]], 'variables.0.options'],
    'choice with one option' => [['body' => '{{q}}', 'variables' => [['name' => 'q', 'label' => 'Q', 'type' => 'choice', 'options' => ['a']]]], 'variables.0.options'],
    'default on a series' => [['body' => '{{q}}', 'variables' => [['name' => 'q', 'label' => 'Q', 'type' => 'series', 'default' => 'Frieren']]], 'variables.0.default'],
    'choice default outside options' => [['body' => '{{q}}', 'variables' => [['name' => 'q', 'label' => 'Q', 'type' => 'choice', 'options' => ['a', 'b'], 'default' => 'c']]], 'variables.0.default'],
    'non-integer number default' => [['body' => '{{q}}', 'variables' => [['name' => 'q', 'label' => 'Q', 'type' => 'number', 'default' => 'two']]], 'variables.0.default'],
    'bad variable name' => [['body' => '{{q}}', 'variables' => [['name' => 'Q', 'label' => 'Q', 'type' => 'text']]], 'variables.0.name'],
    'unknown type' => [['body' => '{{q}}', 'variables' => [['name' => 'q', 'label' => 'Q', 'type' => 'episode']]], 'variables.0.type'],
    'duplicate variable' => [['body' => '{{q}}', 'variables' => [['name' => 'q', 'label' => 'Q', 'type' => 'text'], ['name' => 'q', 'label' => 'Q2', 'type' => 'text']]], 'variables.1.name'],
    'missing label' => [['body' => '{{q}}', 'variables' => [['name' => 'q', 'label' => '', 'type' => 'text']]], 'variables.0.label'],
    'body too long' => [['body' => str_repeat('a', 4001), 'variables' => []], 'body'],
    'blank name' => [['name' => ''], 'name'],
]);

test('names are unique per user but may repeat across users', function (): void {
    $admin = User::factory()->admin()->create();
    ChatTemplate::factory()->for($admin)->create(['name' => 'Subtitle check']);
    ChatTemplate::factory()->create(['name' => 'Other user name']);

    $this->actingAs($admin)
        ->post(route('ai.templates.store'), chatTemplatePayload())
        ->assertSessionHasErrors('name');

    $this->actingAs($admin)
        ->post(route('ai.templates.store'), chatTemplatePayload(['name' => 'Other user name']))
        ->assertSessionHasNoErrors();
});

test('the edit page renders the owned template', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->subtitleCheck()->create();

    $this->actingAs($admin)
        ->get(route('ai.templates.edit', $chatTemplate))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('AI/Templates/Edit')
            ->where('template.id', $chatTemplate->id)
            ->where('template.variables.0.type', 'series')
            ->where('prefillBody', ''));
});

test('updating keeps its own name and replaces the definition', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->create(['name' => 'Subtitle check']);

    $this->actingAs($admin)
        ->patch(route('ai.templates.update', $chatTemplate), chatTemplatePayload(['auto_send' => true]))
        ->assertRedirect(route('ai.templates.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Template updated.');

    expect($chatTemplate->fresh())
        ->auto_send->toBeTrue()
        ->variables->toHaveCount(3);
});

test('deleting removes the template', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->create();

    $this->actingAs($admin)
        ->delete(route('ai.templates.destroy', $chatTemplate))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Template deleted.');

    expect($chatTemplate->fresh())->toBeNull();
});

test('pinning toggles the flag both ways', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->create();

    $this->actingAs($admin)->patch(route('ai.templates.pin', $chatTemplate))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Template pinned.');
    expect($chatTemplate->fresh()->pinned)->toBeTrue();

    $this->actingAs($admin)->patch(route('ai.templates.pin', $chatTemplate))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Template unpinned.');
    expect($chatTemplate->fresh()->pinned)->toBeFalse();
});
