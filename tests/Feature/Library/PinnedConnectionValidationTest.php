<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('every pinned write refuses a missing or unusable connection pin before anything is sent', function (string $method, string $routeName, array $parameters, array $data, array $pin): void {
    $request = $this->actingAs(User::factory()->admin()->create())->from(route('dashboard'));

    $response = $method === 'get'
        ? $request->get(route($routeName, [...$parameters, ...$data, ...$pin]))
        : $request->{$method}(route($routeName, $parameters), [...$data, ...$pin]);

    $response->assertSessionHasErrors('service_connection_id');
    Http::assertNothingSent();
})->with([
    'force grab' => ['post', 'media.library.activity.queue.grab', ['service' => 'sonarr', 'id' => 55], []],
    'queue removal' => ['post', 'media.library.activity.queue.remove', ['service' => 'sonarr', 'id' => 42], ['verb' => 'remove']],
    'bulk queue removal' => ['post', 'media.library.activity.queue.bulk', [], ['service' => 'sonarr', 'ids' => [1], 'action' => 'remove']],
    'manual import candidates' => ['get', 'media.library.activity.manual-import.candidates', ['service' => 'sonarr', 'downloadId' => 'dl-1'], []],
    'manual import' => ['post', 'media.library.activity.manual-import.execute', ['service' => 'sonarr'], ['download_id' => 'dl-1']],
    'mark failed' => ['post', 'media.library.activity.history.failed', ['service' => 'sonarr', 'id' => 9], []],
    'series delete' => ['delete', 'media.series.destroy', ['id' => 1], ['delete_files' => false]],
])->with([
    'missing' => [[]],
    'zero' => [['service_connection_id' => 0]],
    'not a number' => [['service_connection_id' => 'abc']],
]);
