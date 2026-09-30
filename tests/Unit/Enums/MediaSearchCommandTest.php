<?php

declare(strict_types=1);

use App\Enums\MediaSearchCommand;
use App\Enums\ServiceType;

test('each command maps to its arr command name, service and parameters', function (MediaSearchCommand $command, string $arrCommand, ServiceType $service, array $payload, array $parameters): void {
    expect($command->arrCommand())->toBe($arrCommand)
        ->and($command->service())->toBe($service)
        ->and($command->arrParameters($payload))->toBe($parameters);
})->with([
    'series' => [MediaSearchCommand::SeriesSearch, 'SeriesSearch', ServiceType::Sonarr, ['series_id' => 7], ['seriesId' => 7]],
    'season' => [MediaSearchCommand::SeasonSearch, 'SeasonSearch', ServiceType::Sonarr, ['series_id' => 7, 'season_number' => 0], ['seriesId' => 7, 'seasonNumber' => 0]],
    'episodes' => [MediaSearchCommand::EpisodeSearch, 'EpisodeSearch', ServiceType::Sonarr, ['episode_ids' => [3, 3, 4]], ['episodeIds' => [3, 4]]],
    'missing episodes' => [MediaSearchCommand::MissingEpisodeSearch, 'MissingEpisodeSearch', ServiceType::Sonarr, [], ['monitored' => true]],
    'cutoff episodes' => [MediaSearchCommand::CutoffUnmetEpisodeSearch, 'CutoffUnmetEpisodeSearch', ServiceType::Sonarr, [], ['monitored' => true]],
    'movies' => [MediaSearchCommand::MoviesSearch, 'MoviesSearch', ServiceType::Radarr, ['movie_ids' => [10]], ['movieIds' => [10]]],
    'missing movies' => [MediaSearchCommand::MissingMoviesSearch, 'MissingMoviesSearch', ServiceType::Radarr, [], []],
    'cutoff movies' => [MediaSearchCommand::CutoffUnmetMoviesSearch, 'CutoffUnmetMoviesSearch', ServiceType::Radarr, [], []],
]);

test('a targeted command without its ids is rejected', function (MediaSearchCommand $command, array $payload): void {
    expect(fn (): array => $command->arrParameters($payload))->toThrow(InvalidArgumentException::class);
})->with([
    'series without id' => [MediaSearchCommand::SeriesSearch, []],
    'season without number' => [MediaSearchCommand::SeasonSearch, ['series_id' => 7]],
    'episodes empty' => [MediaSearchCommand::EpisodeSearch, ['episode_ids' => []]],
    'movies with junk' => [MediaSearchCommand::MoviesSearch, ['movie_ids' => [0, -1]]],
]);

test('only the missing and cutoff searches are library wide', function (): void {
    expect(array_values(array_filter(MediaSearchCommand::cases(), fn (MediaSearchCommand $command): bool => $command->isLibraryWide())))
        ->toBe([
            MediaSearchCommand::MissingEpisodeSearch,
            MediaSearchCommand::CutoffUnmetEpisodeSearch,
            MediaSearchCommand::MissingMoviesSearch,
            MediaSearchCommand::CutoffUnmetMoviesSearch,
        ]);
});
