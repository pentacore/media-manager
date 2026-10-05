<?php

declare(strict_types=1);

use App\Services\Bazarr\SubtitleLibraryReader;
use Illuminate\Support\Facades\Http;

test('the library reader caps a page at one hundred rows', function (): void {
    expect(SubtitleLibraryReader::MAX_PER_PAGE)->toBe(100);
});

test('page validation refuses out-of-range pages with the existing messages', function (int $page, int $perPage, string $message): void {
    Http::preventStrayRequests();

    expect(fn () => resolve(SubtitleLibraryReader::class)->validatePagination($page, $perPage))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'page zero' => [0, 25, 'Page must be positive.'],
    'per page zero' => [1, 0, 'Per page must be between 1 and 100.'],
    'per page over the cap' => [1, 101, 'Per page must be between 1 and 100.'],
]);

test('page validation accepts the bounds', function (int $perPage): void {
    resolve(SubtitleLibraryReader::class)->validatePagination(1, $perPage);

    expect(true)->toBeTrue();
})->with([1, 100]);
