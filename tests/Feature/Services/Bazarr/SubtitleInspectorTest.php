<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleInspector;
use App\Services\ServiceClientFactory;
use Illuminate\Support\Facades\Http;

test('the inspector validates the media identity before building a client', function (string $mediaType, int $mediaId, string $message): void {
    Http::preventStrayRequests();
    $bazarr = ServiceConnection::factory()->bazarr()->create(['url' => 'http://bazarr.test']);
    $serviceClientFactory = Mockery::mock(ServiceClientFactory::class);
    $serviceClientFactory->shouldNotReceive('make');
    app()->instance(ServiceClientFactory::class, $serviceClientFactory);

    expect(fn (): array => resolve(SubtitleInspector::class)->inspect($bazarr, $mediaType, $mediaId))
        ->toThrow(new InvalidArgumentException($message));
})->with([
    'unsupported media type' => ['season', 1, 'Media type must be episode or movie.'],
    'non-positive media id' => ['episode', -1, 'Media ID must be positive.'],
]);
