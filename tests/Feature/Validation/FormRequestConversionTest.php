<?php

declare(strict_types=1);

use App\Http\Controllers\Bazarr\HistoryController;
use App\Http\Controllers\Bazarr\LibraryController;
use App\Http\Controllers\Bazarr\MissingController;
use App\Http\Controllers\Bazarr\OverviewController;
use App\Http\Controllers\Media\AnimeController;
use App\Http\Controllers\Media\RequestController;
use App\Http\Requests\Bazarr\HistoryPageRequest;
use App\Http\Requests\Bazarr\LibraryPageRequest;
use App\Http\Requests\Bazarr\MissingPageRequest;
use App\Http\Requests\Bazarr\OverviewPageRequest;
use App\Http\Requests\Media\ConfirmAnimeMatchRequest;
use App\Http\Requests\Media\FindAnimeMatchRequest;
use App\Http\Requests\Media\RequestAnimeRequest;
use App\Http\Requests\Media\UpdateSeerrRequestRequest;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
});

test('each converted action validates through its Form Request', function (string $controller, string $method, string $formRequest): void {
    $parameterTypes = array_map(
        static fn (ReflectionParameter $reflectionParameter): string => (string) $reflectionParameter->getType(),
        new ReflectionMethod($controller, $method)->getParameters(),
    );

    expect($parameterTypes)->toContain($formRequest);
})->with([
    'seerr request edit' => [RequestController::class, 'update', UpdateSeerrRequestRequest::class],
    'anime request' => [AnimeController::class, 'request', RequestAnimeRequest::class],
    'anime find match' => [AnimeController::class, 'findMatch', FindAnimeMatchRequest::class],
    'anime confirm match' => [AnimeController::class, 'confirmMatch', ConfirmAnimeMatchRequest::class],
    'subtitle overview' => [OverviewController::class, '__invoke', OverviewPageRequest::class],
    'subtitle library' => [LibraryController::class, '__invoke', LibraryPageRequest::class],
    'subtitle missing' => [MissingController::class, '__invoke', MissingPageRequest::class],
    'subtitle history' => [HistoryController::class, '__invoke', HistoryPageRequest::class],
]);

test('a Seerr request edit without a root folder is refused before Seerr is called', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake(['seerr.local:5055/*' => Http::response([], 500)]);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('media.requests.index'))
        ->put(route('media.requests.update', 42), ['profile_id' => 9])
        ->assertSessionHasErrors('root_folder');

    Http::assertNothingSent();
});

test('an anime match search without a title and a confirmation without a TMDB id are refused', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake(['seerr.local:5055/*' => Http::response([], 500)]);
    $member = User::factory()->member()->create();

    $this->actingAs($member)->from(route('media.anime.index'))
        ->post(route('media.anime.find-match'), [])
        ->assertSessionHasErrors('title');
    $this->actingAs($member)->from(route('media.anime.index'))
        ->post(route('media.anime.confirm-match'), ['anilistId' => 5, 'mediaType' => 'tv'])
        ->assertSessionHasErrors('tmdbId');

    Http::assertNothingSent();
});
