<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Whisparr\WhisparrActions;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->whisparr()->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k', 'is_active' => true,
    ]);
});

test('deleteItem sends DELETE to the movie resource with deleteFiles', function (): void {
    Http::fake(['whisparr.local:6969/api/v3/movie/99*' => Http::response(null, 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'whisparr_delete_item',
        'payload' => ['whisparr_item_id' => 99, 'delete_files' => true],
    ]);

    $result = (new WhisparrActions)->execute($request);

    expect($result)->toMatchArray(['whisparr_item_id' => 99, 'delete_files' => true]);
    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
        && str_contains((string) $r->url(), '/api/v3/movie/99')
        && str_contains((string) $r->url(), 'deleteFiles=true'));
});

test('deleteItem throws when whisparr_item_id is missing', function (): void {
    $request = ActionRequest::factory()->create(['type' => 'whisparr_delete_item', 'payload' => []]);
    expect(fn (): array => (new WhisparrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('execute throws for an unknown type', function (): void {
    $request = ActionRequest::factory()->create(['type' => 'zzz', 'payload' => []]);
    expect(fn (): array => (new WhisparrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('ExecuteActionRequest routes whisparr_* types to WhisparrActions', function (): void {
    $job = new ExecuteActionRequest(
        ActionRequest::factory()->create(['type' => 'whisparr_add_item', 'payload' => []]),
    );
    $resolve = new ReflectionMethod(ExecuteActionRequest::class, 'resolveExecutor');
    expect($resolve->invoke($job, 'whisparr_add_item'))->toBeInstanceOf(WhisparrActions::class);
});

test('addItem sends searchForMissingEpisodes (not searchForMovie) for a v2/series connection', function (): void {
    ServiceConnection::where('is_active', true)->update(['is_active' => false]);
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V2)->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k', 'is_active' => true,
    ]);

    Http::fake([
        'whisparr.local:6969/api/v3/series/lookup*' => Http::response([['title' => 'X', 'tvdbId' => 1]], 200),
        'whisparr.local:6969/api/v3/series' => Http::response(['id' => 42, 'title' => 'X'], 201),
    ]);

    $request = ActionRequest::factory()->create([
        'type' => 'whisparr_add_item',
        'payload' => ['tmdb_id' => 1, 'quality_profile_id' => 1, 'root_folder_path' => '/media'],
    ]);

    $result = (new WhisparrActions)->execute($request);

    expect($result)->toMatchArray(['whisparr_item_id' => 42, 'title' => 'X', 'tmdb_id' => 1]);

    Http::assertSent(function ($r): bool {
        if ($r->method() !== 'POST' || ! str_contains((string) $r->url(), '/api/v3/series')) {
            return false;
        }
        $body = json_decode((string) $r->body(), true);

        return isset($body['addOptions']['searchForMissingEpisodes'])
            && $body['addOptions']['searchForMissingEpisodes'] === true
            && ! isset($body['addOptions']['searchForMovie']);
    });
});
