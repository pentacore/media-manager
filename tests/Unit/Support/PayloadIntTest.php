<?php

declare(strict_types=1);

use App\Support\PayloadInt;

test('it returns a positive id as an integer', function (mixed $value, int $expected): void {
    expect(PayloadInt::required(['series_id' => $value], 'series_id'))->toBe($expected);
})->with([
    'an integer' => [42, 42],
    'a numeric string' => ['42', 42],
    'a float' => [42.0, 42],
]);

test('a missing or null id is reported as required', function (array $payload): void {
    expect(fn (): int => PayloadInt::required($payload, 'series_id'))
        ->toThrow(function (InvalidArgumentException $invalidArgumentException): void {
            expect($invalidArgumentException->getMessage())->toBe('series_id is required');
        });
})->with([
    'absent' => [[]],
    'null' => [['series_id' => null]],
]);

test('a present id that is not a positive integer is reported as invalid', function (mixed $value): void {
    expect(fn (): int => PayloadInt::required(['series_id' => $value], 'series_id'))
        ->toThrow(function (InvalidArgumentException $invalidArgumentException): void {
            expect($invalidArgumentException->getMessage())->toBe('series_id must be a positive integer');
        });
})->with([
    'zero' => [0],
    'negative' => [-3],
    'not numeric' => ['abc'],
    'empty string' => [''],
    'false' => [false],
    'a fraction below one' => [0.5],
]);
