<?php

declare(strict_types=1);

use App\Enums\ChatTemplateVariableType;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Chat\ChatTemplateLibrary;
use App\Services\Chat\ChatTemplateLibraryTitle;

test('series search reads only the active Sonarr connection, case-insensitively, ordered by title', function (): void {
    $active = ServiceConnection::factory()->sonarr()->create();
    $inactive = ServiceConnection::factory()->sonarr()->inactive()->create();
    IndexedSeries::factory()->create(['service_connection_id' => $active->id, 'sonarr_id' => 2, 'title' => 'Frieren', 'year' => 2023]);
    IndexedSeries::factory()->create(['service_connection_id' => $active->id, 'sonarr_id' => 3, 'title' => 'Dungeon Meshi', 'year' => 2024]);
    IndexedSeries::factory()->create(['service_connection_id' => $inactive->id, 'sonarr_id' => 4, 'title' => 'Frieren Copy']);

    $results = resolve(ChatTemplateLibrary::class)->search(ChatTemplateVariableType::Series, 'FRIE');

    expect(array_column($results, 'id'))->toBe([2])
        ->and($results[0])->toHaveKeys(['id', 'title', 'year', 'poster_url'])
        ->and($results[0]['title'])->toBe('Frieren');
});

test('search caps results at the limit', function (): void {
    $active = ServiceConnection::factory()->radarr()->create();
    IndexedMovie::factory()->count(12)->sequence(fn ($sequence): array => ['title' => sprintf('Dune %02d', $sequence->index)])
        ->create(['service_connection_id' => $active->id]);

    expect(resolve(ChatTemplateLibrary::class)->search(ChatTemplateVariableType::Movie, 'dune'))->toHaveCount(ChatTemplateLibrary::SEARCH_LIMIT);
});

test('LIKE wildcards in the search term match literally', function (string $term, array $expectedTitles): void {
    $active = ServiceConnection::factory()->sonarr()->create();
    IndexedSeries::factory()->create(['service_connection_id' => $active->id, 'title' => '100% Pascal-sensei']);
    IndexedSeries::factory()->create(['service_connection_id' => $active->id, 'title' => 'Snake_Case Show']);
    IndexedSeries::factory()->create(['service_connection_id' => $active->id, 'title' => 'Frieren']);

    expect(array_column(resolve(ChatTemplateLibrary::class)->search(ChatTemplateVariableType::Series, $term), 'title'))->toBe($expectedTitles);
})->with([
    'percent' => ['%', ['100% Pascal-sensei']],
    'underscore' => ['_', ['Snake_Case Show']],
]);

test('search without an active connection is empty', function (): void {
    ServiceConnection::factory()->sonarr()->inactive()->create();

    expect(resolve(ChatTemplateLibrary::class)->search(ChatTemplateVariableType::Series, 'a'))->toBe([]);
});

test('find resolves a title on the active connection only', function (): void {
    $active = ServiceConnection::factory()->radarr()->create();
    $inactive = ServiceConnection::factory()->radarr()->inactive()->create();
    IndexedMovie::factory()->create(['service_connection_id' => $active->id, 'radarr_id' => 17, 'title' => 'Dune', 'year' => 2021]);
    IndexedMovie::factory()->create(['service_connection_id' => $inactive->id, 'radarr_id' => 18, 'title' => 'Arrival']);

    $chatTemplateLibrary = resolve(ChatTemplateLibrary::class);

    expect($chatTemplateLibrary->find(ChatTemplateVariableType::Movie, 17))->toEqual(new ChatTemplateLibraryTitle(ChatTemplateVariableType::Movie, 'Dune', 2021, 17))
        ->and($chatTemplateLibrary->find(ChatTemplateVariableType::Movie, 18))->toBeNull();
});
