<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Services\Arr\ArrActions;
use App\Services\Radarr\RadarrActions;
use App\Services\Sonarr\SonarrActions;

test('it hands each request to the executor named by payload.service', function (string $service, string $executor): void {
    $actionRequest = ActionRequest::factory()->create(['type' => 'search_media', 'payload' => ['service' => $service]]);
    $mock = Mockery::mock($executor);
    $mock->shouldReceive('execute')->once()->with($actionRequest)->andReturn(['routed' => $service]);
    app()->instance($executor, $mock);

    expect(resolve(ArrActions::class)->execute($actionRequest))->toBe(['routed' => $service]);
})->with([
    'sonarr' => ['sonarr', SonarrActions::class],
    'radarr' => ['radarr', RadarrActions::class],
]);

test('a payload without a known service is rejected', function (): void {
    expect(fn (): array => resolve(ArrActions::class)->execute(ActionRequest::factory()->create(['type' => 'grab_release', 'payload' => ['service' => 'whisparr']])))
        ->toThrow(InvalidArgumentException::class);
});
