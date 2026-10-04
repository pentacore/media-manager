<?php

declare(strict_types=1);

use App\Models\ChatTemplate;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

test('options lists own templates pinned first, then most recently used, then by name', function (): void {
    $admin = User::factory()->admin()->create();
    ChatTemplate::factory()->for($admin)->pinned()->lastUsedAt(CarbonImmutable::now()->subDays(2))->create(['name' => 'A pinned older']);
    ChatTemplate::factory()->for($admin)->pinned()->lastUsedAt(CarbonImmutable::now())->create(['name' => 'B pinned newest']);
    ChatTemplate::factory()->for($admin)->create(['name' => 'Alpha never used']);
    ChatTemplate::factory()->for($admin)->lastUsedAt(CarbonImmutable::now()->subDay())->create(['name' => 'D used yesterday']);
    ChatTemplate::factory()->for($admin)->create(['name' => 'Beta never used']);
    ChatTemplate::factory()->create(['name' => 'Foreign']);

    $response = $this->actingAs($admin)->getJson(route('ai.templates.options'))->assertOk();

    expect(array_column($response->json('templates'), 'name'))->toBe([
        'B pinned newest', 'A pinned older', 'D used yesterday', 'Alpha never used', 'Beta never used',
    ]);
});

test('library search returns matches from the active connection', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    IndexedSeries::factory()->create(['service_connection_id' => $sonarr->id, 'sonarr_id' => 42, 'title' => 'Frieren', 'year' => 2023, 'poster_url' => null]);

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('ai.templates.library', ['type' => 'series', 'q' => 'fri']))
        ->assertOk()
        ->assertJsonPath('items.0', ['id' => 42, 'title' => 'Frieren', 'year' => 2023, 'poster_url' => null]);
});

test('library search validates its query', function (array $query, string $field): void {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('ai.templates.library', $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing q' => [['type' => 'series'], 'q'],
    'non-library type' => [['type' => 'text', 'q' => 'a'], 'type'],
]);

test('library search treats wildcards literally', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    IndexedSeries::factory()->create(['service_connection_id' => $sonarr->id, 'title' => 'Frieren']);

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('ai.templates.library', ['type' => 'series', 'q' => '%']))
        ->assertOk()
        ->assertJsonPath('items', []);
});

test('preview returns segments and definition errors without failing the request', function (): void {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('ai.templates.preview'), [
            'body' => 'Check {{anime:poster}} and {{q:title}}',
            'variables' => [['name' => 'q', 'label' => 'Query', 'type' => 'text']],
        ])
        ->assertOk();

    expect($response->json('segments.0.kind'))->toBe('text')
        ->and(implode(' ', $response->json('errors.body')))->toContain('unknown part')
        ->and(implode(' ', $response->json('errors.body')))->toContain('only series and movie variables have parts');
});

test('preview of an empty draft has no segments and an empty errors object', function (): void {
    $response = $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('ai.templates.preview'), ['body' => '', 'variables' => []])
        ->assertOk();

    expect($response->json('segments'))->toBe([])
        ->and($response->getContent())->toContain('"errors":{}');
});

test('preview rejects a malformed request', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('ai.templates.preview'), ['body' => 'x', 'variables' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('variables');
});

test('render fills the template, ignores unknown values and stamps last use', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00:00'));
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    IndexedSeries::factory()->create(['service_connection_id' => $sonarr->id, 'sonarr_id' => 42, 'title' => 'Frieren', 'year' => 2023]);
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->subtitleCheck()->create();

    $this->actingAs($admin)
        ->postJson(route('ai.templates.render', $chatTemplate), [
            'values' => ['anime' => 42, 'season' => 1, 'episode' => 7, 'injected' => 'IGNORE ME'],
        ])
        ->assertOk()
        ->assertJsonPath('text', 'Check Frieren (2023) S1E7 for subtitles');

    expect($chatTemplate->fresh()->last_used_at?->toIso8601String())->toBe('2026-10-04T10:00:00+00:00');
});

test('render rejects bad values on their field', function (array $values, string $field): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->withBody('{{what}} {{lang}} {{n}}', [
        ['name' => 'what', 'label' => 'What', 'type' => 'text', 'default' => null, 'options' => null],
        ['name' => 'lang', 'label' => 'Language', 'type' => 'choice', 'default' => null, 'options' => ['English', 'Swedish']],
        ['name' => 'n', 'label' => 'Number', 'type' => 'number', 'default' => null, 'options' => null],
    ])->create();

    $this->actingAs($admin)
        ->postJson(route('ai.templates.render', $chatTemplate), ['values' => $values])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect($chatTemplate->fresh()->last_used_at)->toBeNull();
})->with([
    'whitespace-only text' => [['what' => '   ', 'lang' => 'English', 'n' => 1], 'values.what'],
    'choice outside options' => [['what' => 'x', 'lang' => 'French', 'n' => 1], 'values.lang'],
    'non-integer number' => [['what' => 'x', 'lang' => 'English', 'n' => 'two'], 'values.n'],
    'missing value' => [['what' => 'x', 'lang' => 'English'], 'values.n'],
]);

test('render after the template gained a variable asks for the new value', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->withBody('{{what}}', [
        ['name' => 'what', 'label' => 'What', 'type' => 'text', 'default' => null, 'options' => null],
    ])->create();
    $chatTemplate->update([
        'body' => '{{what}} {{extra}}',
        'variables' => [...$chatTemplate->variables, ['name' => 'extra', 'label' => 'Extra', 'type' => 'text', 'default' => null, 'options' => null]],
    ]);

    $this->actingAs($admin)
        ->postJson(route('ai.templates.render', $chatTemplate), ['values' => ['what' => 'x']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('values.extra');
});

test('render reports a missing library connection as a field error', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->subtitleCheck()->create();

    $this->actingAs($admin)
        ->postJson(route('ai.templates.render', $chatTemplate), ['values' => ['anime' => 42, 'season' => 1, 'episode' => 7]])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['values.anime' => ['Sonarr is not connected.']]);
});

test('render of a template without variables accepts an empty values object', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->create(['body' => 'Check Sonarr and Radarr for stuck downloads']);

    $this->actingAs($admin)
        ->postJson(route('ai.templates.render', $chatTemplate), ['values' => []])
        ->assertOk()
        ->assertJsonPath('text', 'Check Sonarr and Radarr for stuck downloads');
});

test('another admin\'s template cannot be rendered', function (): void {
    $chatTemplate = ChatTemplate::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('ai.templates.render', $chatTemplate), ['values' => []])
        ->assertNotFound();
});

test('members cannot use the JSON endpoints', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)->getJson(route('ai.templates.options'))->assertForbidden();
    $this->actingAs($member)->getJson(route('ai.templates.library', ['type' => 'series', 'q' => 'a']))->assertForbidden();
    $this->actingAs($member)->postJson(route('ai.templates.preview'), ['body' => '', 'variables' => []])->assertForbidden();
});
