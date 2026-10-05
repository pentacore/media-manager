<?php

declare(strict_types=1);

use App\Models\ChatTemplate;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Chat\ChatTemplateRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, mixed>
 */
function rendererVariable(string $name, string $type, array $overrides = []): array
{
    return array_replace(['name' => $name, 'label' => ucfirst($name), 'type' => $type, 'default' => null, 'options' => null], $overrides);
}

/**
 * @param  array<string, mixed>  $values
 * @return array<string, list<string>>
 */
function rendererErrors(ChatTemplate $chatTemplate, array $values): array
{
    try {
        resolve(ChatTemplateRenderer::class)->render($chatTemplate, $values);
    } catch (ValidationException $validationException) {
        return $validationException->errors();
    }

    return [];
}

test('text, number and choice values fill their tokens', function (): void {
    $chatTemplate = ChatTemplate::factory()->withBody('Check {{what}} in season {{season}} ({{lang}})', [
        rendererVariable('what', 'text'),
        rendererVariable('season', 'number'),
        rendererVariable('lang', 'choice', ['options' => ['English', 'Swedish']]),
    ])->create();

    expect(resolve(ChatTemplateRenderer::class)->render($chatTemplate, ['what' => ' Frieren ', 'season' => '02', 'lang' => 'Swedish']))
        ->toBe('Check Frieren in season 2 (Swedish)');
});

test('series parts render in the order written and default to the title', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    IndexedSeries::factory()->create(['service_connection_id' => $sonarr->id, 'sonarr_id' => 42, 'title' => "Frieren: Beyond Journey's End", 'year' => 2023]);
    $chatTemplate = ChatTemplate::factory()->withBody('{{show}} | {{show:id,title}} | {{show:title,year,id}}', [rendererVariable('show', 'series')])->create();

    expect(resolve(ChatTemplateRenderer::class)->render($chatTemplate, ['show' => 42]))->toBe(
        "Frieren: Beyond Journey's End | (Sonarr series id 42) Frieren: Beyond Journey's End | Frieren: Beyond Journey's End (2023) (Sonarr series id 42)",
    );
});

test('a movie without a year omits the year part', function (): void {
    $radarr = ServiceConnection::factory()->radarr()->create();
    IndexedMovie::factory()->create(['service_connection_id' => $radarr->id, 'radarr_id' => 17, 'title' => 'Dune', 'year' => null]);
    $chatTemplate = ChatTemplate::factory()->withBody('{{film:title,year,id}}', [rendererVariable('film', 'movie')])->create();

    expect(resolve(ChatTemplateRenderer::class)->render($chatTemplate, ['film' => 17]))->toBe('Dune (Radarr movie id 17)');
});

test('library problems are field errors, never exceptions of another kind', function (): void {
    $inactive = ServiceConnection::factory()->sonarr()->inactive()->create();
    IndexedSeries::factory()->create(['service_connection_id' => $inactive->id, 'sonarr_id' => 42]);
    $chatTemplate = ChatTemplate::factory()->withBody('{{show}}', [rendererVariable('show', 'series')])->create();

    expect(rendererErrors($chatTemplate, ['show' => 42]))->toBe(['values.show' => ['Sonarr is not connected.']]);

    ServiceConnection::factory()->sonarr()->create();

    expect(rendererErrors($chatTemplate, ['show' => 42]))->toBe(['values.show' => ['That series is no longer in your library.']]);
});

test('a result over 4000 characters is rejected, counting characters not bytes', function (): void {
    $chatTemplate = ChatTemplate::factory()->withBody(str_repeat('{{v}}', 9), [rendererVariable('v', 'text')])->create();

    expect(rendererErrors($chatTemplate, ['v' => str_repeat('é', 440)]))->toBe([])
        ->and(rendererErrors($chatTemplate, ['v' => str_repeat('é', 450)]))->toBe([
            'values' => ['The filled-in message is longer than 4000 characters.'],
        ]);
});

test('preview shows defaults as text and everything else as placeholders', function (): void {
    expect(resolve(ChatTemplateRenderer::class)->preview('Check {{anime:title,year}} S{{season}}E{{episode}} in {{lang}}', [
        rendererVariable('anime', 'series'),
        rendererVariable('season', 'number', ['default' => '1']),
        rendererVariable('episode', 'number', ['label' => '']),
    ]))->toBe([
        ['kind' => 'text', 'value' => 'Check '],
        ['kind' => 'placeholder', 'value' => '[Anime: title, year]'],
        ['kind' => 'text', 'value' => ' S1E'],
        ['kind' => 'placeholder', 'value' => '[episode]'],
        ['kind' => 'text', 'value' => ' in '],
        ['kind' => 'placeholder', 'value' => '[lang]'],
    ]);
});

test('preview keeps malformed placeholders as literal text and never reads the library', function (): void {
    DB::enableQueryLog();

    $segments = resolve(ChatTemplateRenderer::class)->preview('{{Bad}} and {{show:id}}', [rendererVariable('show', 'series')]);

    expect($segments)->toBe([
        ['kind' => 'text', 'value' => '{{Bad}} and '],
        ['kind' => 'placeholder', 'value' => '[Show: id]'],
    ])->and(DB::getQueryLog())->toBe([]);
});
