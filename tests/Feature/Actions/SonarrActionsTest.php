<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Sonarr\SonarrActions;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);
});

test('deleteSeries sends DELETE to sonarr with deleteFiles flag', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/series/42*' => Http::response(null, 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'delete_series',
        'payload' => ['sonarr_series_id' => 42, 'delete_files' => true],
    ]);

    $result = (new SonarrActions)->execute($request);

    expect($result)->toMatchArray(['sonarr_series_id' => 42, 'delete_files' => true]);

    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
        && str_contains((string) $r->url(), '/api/v3/series/42')
        && str_contains((string) $r->url(), 'deleteFiles=true'));
});

test('deleteSeries defaults delete_files to false', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/series/7*' => Http::response(null, 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'delete_series',
        'payload' => ['sonarr_series_id' => 7],
    ]);

    (new SonarrActions)->execute($request);

    Http::assertSent(fn ($r): bool => str_contains((string) $r->url(), 'deleteFiles=false'));
});

test('deleteSeries throws when sonarr_series_id is missing', function (): void {
    $request = ActionRequest::factory()->create([
        'type' => 'delete_series',
        'payload' => [],
    ]);

    expect(fn (): array => (new SonarrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('execute throws for unknown type', function (): void {
    $request = ActionRequest::factory()->create([
        'type' => 'some_unknown_type',
        'payload' => [],
    ]);

    expect(fn (): array => (new SonarrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});
